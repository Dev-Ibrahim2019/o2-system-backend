<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\AddOrderItemRequest;
use App\Http\Requests\Api\StoreOrderRequest;
use App\Http\Requests\Api\UpdateOrderRequest;
use App\Http\Resources\AccountingResources\TransactionResource;
use App\Http\Resources\OrderItemResource;
use App\Http\Resources\OrderResource;
use App\Models\DiningTable;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductionTicket;
use App\Models\ProductionTicketItem;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\Printing\OrderPrintingService;

class OrderController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $orders = Order::with(['items.department', 'tickets.department', 'cashier'])
            ->when($request->branch_id, fn($q) => $q->where('branch_id', $request->branch_id))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->date, fn($q) => $q->whereDate('created_at', $request->date))
            ->when($request->table_number, fn($q) => $q->where('table_number', $request->table_number))
            ->orderByDesc('id')
            ->get();

        return $this->success('Orders fetched', OrderResource::collection($orders));
    }

    /**
     * إنشاء طلب جديد (pending) — بدون أصناف؛ تُضاف عبر addItem
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $data = $request->validated();

        DB::beginTransaction();
        try {
            $authUser = auth()->user();
            $customer = isset($data['customer_id']) ? Customer::find($data['customer_id']) : null;

            $branchId = $authUser->branch_id ?? $data['branch_id'] ?? \App\Models\Branch::value('id');

            $order = Order::create([
                'order_number' => Order::generateOrderNumber(),
                'dining_table_id' => $data['dining_table_id'] ?? null,
                'branch_id' => $branchId,
                'cashier_id' => $data['cashier_id'] ?? null,
                'call_center_agent_id' => $data['call_center_agent_id'] ?? null,
                'order_type' => $data['order_type'],
                'source' => $data['source'] ?? 'pos',
                'status' => 'pending',
                'table_number' => $data['table_number'] ?? null,
                'customer_name' => $customer?->name ?? ($data['customer_name'] ?? null),
                'customer_phone' => $customer?->phone ?? $customer?->mobile ?? ($data['customer_phone'] ?? null),
                'customer_id' => $data['customer_id'] ?? null,
                'employee_id' => $data['employee_id'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'customer_address_id' => $data['customer_address_id'] ?? null,
                'delivery_zone_id' => $data['delivery_zone_id'] ?? null,
                'delivery_fee' => $data['delivery_fee'] ?? 0,
                'delivery_address_snapshot' => $data['delivery_address_snapshot'] ?? null,
                'delivery_notes' => $data['delivery_notes'] ?? null,
                'call_notes' => $data['call_notes'] ?? null,
                'note' => $data['note'] ?? null,
                'subtotal' => 0,
                'discount_value' => $data['discount_value'] ?? 0,
                'discount_type' => $data['discount_type'] ?? 'amount',
                'discount_amount' => 0,
                'engine_discount_amount' => 0,
                'total' => 0,
            ]);

            // إذا تم تحديد طاولة، قم بتسكينها (OCCUPIED — مشغولة بدون طلب)
            if ($order->dining_table_id) {
                $table = DiningTable::find($order->dining_table_id);
                if ($table && $table->status === 'AVAILABLE') {
                    $table->setOccupied($order->id);
                }
            }

            if (! empty($data['items'])) {
                foreach ($data['items'] as $row) {
                    $this->createOrderItem(
                        $order,
                        (int) $row['item_id'],
                        (float) $row['quantity'],
                        isset($row['unit_price']) ? (float) $row['unit_price'] : null,
                        $row['notes'] ?? null,
                        $row['is_takeaway'] ?? false
                    );
                }
                $order->recalculateTotals();
                // لا نرسل تلقائياً — ينتظر تأكيد النادل عبر confirm()
            }

            DB::commit();

            return $this->success(
                'تم إنشاء الطلب',
                new OrderResource($order->load(['items.department', 'cashier'])),
                201
            );
        } catch (\InvalidArgumentException $e) {
            DB::rollBack();

            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->error('فشل إنشاء الطلب: ' . $e->getMessage(), 500);
        }
    }

    public function show(Order $order): JsonResponse
    {
        return $this->success(
            'Order fetched',
            new OrderResource($order->load([
                'items.department',
                'tickets.ticketItems.orderItem',
                'tickets.department',
                'cashier',
                'invoice.items',
                'invoice.payments',
            ]))
        );
    }

    public function printSections(Order $order): JsonResponse
    {
        return $this->success('أجزاء الطلب للطباعة', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'is_split' => $order->tickets()->exists(),
            'sections' => $order->sectionsForPrint(),
        ]);
    }

    public function update(UpdateOrderRequest $request, Order $order): JsonResponse
    {
        if (in_array($order->status, ['paid', 'cancelled'], true)) {
            return $this->error('لا يمكن تعديل طلب مغلق أو ملغى.', 422);
        }

        DB::beginTransaction();
        try {
            $order->update($request->safe()->except('items'));

            // مزامنة الأصناف إذا تم إرسالها
            if ($request->has('items')) {
                \Log::info('[OrderController::update] items data:', collect($request->items)->map(fn($r) => ['item_id' => $r['item_id'] ?? null, 'is_takeaway' => $r['is_takeaway'] ?? 'MISSING'])->toArray());
                \Log::info('[OrderController::update] existing items:', $order->items()->get(['item_id', 'is_takeaway', 'is_printed_direct'])->toArray());
                // تحديث is_takeaway للأصناف المطبوعة (المحفوظة مسبقاً)
                foreach ($request->items as $row) {
                    $existingItem = $order->items()
                        ->where('item_id', $row['item_id'])
                        ->where('is_printed_direct', true)
                        ->first();

                    if ($existingItem) {
                        $existingItem->update([
                            'is_takeaway' => $row['is_takeaway'] ?? false,
                        ]);
                    }
                }

                // مسح الأصناف غير المطبوعة فقط (المحفوظة بـ is_printed_direct=true لا تُحذف)
                $order->items()
                    ->where('status', 'pending')
                    ->where('is_printed_direct', false)
                    ->delete();

                foreach ($request->items as $row) {
                    // تخطي الأصناف المطبوعة (تم تحديثها أعلاه)
                    $alreadyPrinted = $order->items()
                        ->where('item_id', $row['item_id'])
                        ->where('is_printed_direct', true)
                        ->exists();

                    if ($alreadyPrinted) {
                        continue;
                    }

                    $this->createOrderItem(
                        $order,
                        (int) $row['item_id'],
                        (float) $row['quantity'],
                        isset($row['unit_price']) ? (float) $row['unit_price'] : null,
                        $row['notes'] ?? null,
                        $row['is_takeaway'] ?? false
                    );
                }

                if (! $request->boolean('skip_sync')) {
                    $this->syncProductionTickets($order);
                }
            }

            $order->recalculateTotals();

            DB::commit();

            return $this->success(
                'تم تحديث الطلب',
                new OrderResource($order->fresh()->load(['items.department', 'cashier']))
            );
        } catch (\InvalidArgumentException $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('فشل تحديث الطلب: ' . $e->getMessage(), 500);
        }
    }

    public function syncPricing(UpdateOrderRequest $request, Order $order): JsonResponse
    {
        if (in_array($order->status, ['paid', 'cancelled'], true)) {
            return $this->error('لا يمكن تعديل تسعير طلب مغلق أو ملغى.', 422);
        }

        try {
            $order->update($request->validated());
            $order->recalculateTotals();

            return $this->success(
                'تمت مزامنة التسعير',
                new OrderResource($order->fresh()->load(['items.department', 'cashier']))
            );
        } catch (\Throwable $e) {
            return $this->error('فشل مزامنة التسعير: ' . $e->getMessage(), 500);
        }
    }

    public function addItem(AddOrderItemRequest $request, Order $order): JsonResponse
    {
        // يسمح الإضافة على: pending, pending_confirmation, confirmed, in_progress
        if (in_array($order->status, ['paid', 'cancelled', 'served', 'ready'])) {
            return $this->error('لا يمكن إضافة أصناف لهذا الطلب.', 422);
        }

        $data = $request->validated();

        try {
            $orderItem = $this->createOrderItem(
                $order,
                (int) $data['item_id'],
                (float) $data['quantity'],
                isset($data['unit_price']) ? (float) $data['unit_price'] : null,
                $data['notes'] ?? null,
                $data['is_takeaway'] ?? false
            );

            $order->recalculateTotals();

            // إذا الطلب مؤكد مسبقاً (confirmed/in_progress) وأضفنا عناصر جديدة
            // نحتاج النادل يضغط "ترحيل" مرة ثانية للعناصر الجديدة فقط
            // لا نغير حالة الطلب — نتركها كما هي

            return $this->success(
                'تمت إضافة الصنف',
                [
                    'order' => new OrderResource($order->fresh()->load(['items.department', 'cashier'])),
                    'added_item' => new OrderItemResource($orderItem),
                ],
                201
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('فشل إضافة الصنف: ' . $e->getMessage(), 500);
        }
    }

    public function removeItem(Order $order, OrderItem $orderItem): JsonResponse
    {
        if (in_array($order->status, ['paid', 'cancelled', 'served'])) {
            return $this->error('لا يمكن حذف أصناف من هذا الطلب.', 422);
        }

        if ($orderItem->order_id !== $order->id) {
            return $this->error('الصنف لا ينتمي لهذا الطلب.', 422);
        }

        if ($orderItem->status !== 'pending') {
            return $this->error('لا يمكن حذف صنف أُرسل للمطبخ.', 422);
        }

        try {
            $orderItem->delete();
            $order->recalculateTotals();

            return $this->success(
                'تم حذف الصنف',
                new OrderResource($order->fresh()->load(['items.department', 'cashier']))
            );
        } catch (\Throwable $e) {
            return $this->error('فشل حذف الصنف: ' . $e->getMessage(), 500);
        }
    }

    /**
     * ترحيل الأصناف غير المرحّلة للمطبخ.
     * يدعم: pending → confirmed و pending_confirmation → confirmed
     * يرسل فقط العناصر بحالة pending (الجديدة) — لا يعيد إرسال القديمة.
     */
    public function confirm(Order $order): JsonResponse
    {
        if ($order->source === 'call_center' && $order->payment_status !== 'paid') {
            return $this->error('لا يمكن إرسال طلب الكول سنتر للمطبخ قبل اكتمال الفاتورة والدفع.', 422);
        }

        try {
            $released = app(\App\Services\Order\OrderConfirmationService::class)->release($order);

            return $this->success(
                'تم إرسال الطلب للأقسام',
                new OrderResource($released->load([
                    'items.department',
                    'tickets.ticketItems.orderItem',
                    'tickets.department',
                ]))
            );
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        } catch (\Throwable $e) {
            \Log::error('فشل تأكيد الطلب #' . $order->id, [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error('فشل تأكيد الطلب: ' . $e->getMessage(), 500);
        }
    }

    public function cancel(Order $order): JsonResponse
    {
        if (! in_array($order->status, ['pending', 'pending_confirmation', 'confirmed'], true)) {
            return $this->error('لا يمكن إلغاء هذا الطلب في حالته الحالية.', 422);
        }

        try {
            DB::transaction(function () use ($order) {
                $order->update(['status' => 'cancelled']);
                $order->tickets()->update(['status' => 'cancelled']);
                $order->items()->update(['status' => 'cancelled']);

                // تحرير الطاولة إذا كانت مرتبطة
                if ($order->dining_table_id) {
                    $table = DiningTable::find($order->dining_table_id);
                    if ($table && $table->current_order_id == $order->id) {
                        $table->setAvailable();
                    }
                }
            });

            return $this->success('تم إلغاء الطلب', new OrderResource($order->fresh()));
        } catch (\Throwable $e) {
            return $this->error('فشل إلغاء الطلب: ' . $e->getMessage(), 500);
        }
    }

    public function serve(Order $order): JsonResponse
    {
        if (! in_array($order->status, ['ready', 'in_progress'], true)) {
            return $this->error('يجب أن يكون الطلب جاهزاً قبل التسليم.', 422);
        }

        try {
            DB::transaction(function () use ($order) {
                $order->tickets()
                    ->whereIn('status', ['ready', 'preparing', 'pending'])
                    ->update([
                        'status' => 'served',
                        'served_at' => now(),
                    ]);

                $order->update(['status' => 'served']);
                $order->items()->whereIn('status', ['ready', 'pending', 'preparing'])->update(['status' => 'served']);
            });

            return $this->success(
                'تم تسليم الطلب',
                new OrderResource($order->fresh()->load(['items.department', 'tickets.department']))
            );
        } catch (\Throwable $e) {
            return $this->error('فشل تسليم الطلب: ' . $e->getMessage(), 500);
        }
    }

    /**
     * تأجيل الطلب — نقله لحالة بانتظار الدفع وتحرير الطاولة
     */
    public function deferOrder(Order $order): JsonResponse
    {
        if (in_array($order->status, ['paid', 'cancelled', 'pending_payment'], true)) {
            return $this->error('لا يمكن تأجيل هذا الطلب في حالته الحالية.', 422);
        }

        try {
            DB::transaction(function () use ($order) {
                // تحرير الطاولة أولاً قبل تحديث الطلب
                $table = null;

                if ($order->dining_table_id) {
                    $table = DiningTable::find($order->dining_table_id);
                }
                if (! $table) {
                    $table = DiningTable::where('current_order_id', $order->id)->first();
                }
                if (! $table && $order->table_number && $order->branch_id) {
                    $table = DiningTable::where('table_number', $order->table_number)
                        ->where('branch_id', $order->branch_id)
                        ->first();
                }

                if ($table) {
                    $table->setAvailable();
                }

                // حفظ رقم الطاولة في الملاحظات قبل إزالتها
                $deferredTableNote = $order->table_number ? "[Table: {$order->table_number}]" : "";
                $existingNote = $order->note ?? "";
                $newNote = $deferredTableNote . ($existingNote && $deferredTableNote ? " | " . $existingNote : ($existingNote ?: $deferredTableNote));

                // تحديث حالة الطلب إلى مؤجل + إزالة ارتباطه بالطاولة
                $order->update([
                    'status' => 'pending_payment',
                    'dining_table_id' => null,
                    'table_number' => null,
                    'note' => $newNote,
                ]);
            });

            return $this->success('تم تأجيل الطلب', new OrderResource($order->fresh()->load(['items.department'])));
        } catch (\Throwable $e) {
            return $this->error('فشل تأجيل الطلب: ' . $e->getMessage(), 500);
        }
    }

    public function transfer(Request $request, Order $order): JsonResponse
    {
        \Log::info('[ORDER TRANSFER] Called', [
            'order_id' => $order->id,
            'table_number' => $request->input('table_number'),
            'order_dining_table_id' => $order->dining_table_id,
            'order_table_number' => $order->table_number,
            'order_status' => $order->status,
            'order_branch_id' => $order->branch_id,
        ]);

        $request->validate([
            'table_number' => 'required|string',
        ]);

        // منع النقل لطلبات في حالات نهائية
        if (in_array($order->status, ['paid', 'cancelled', 'served', 'pending_payment'], true)) {
            \Log::warning('[ORDER TRANSFER] Order in invalid status', ['status' => $order->status]);
            return $this->error('لا يمكن نقل هذا الطلب في حالته الحالية.', 422);
        }

        // التحقق من أن الطلب مرتبط بطاولة (ليس takeaway)
        if (! $order->dining_table_id && ! $order->table_number) {
            \Log::warning('[ORDER TRANSFER] Order not associated with any table', ['order_id' => $order->id]);
            return $this->error('هذا الطلب غير مرتبط بطاولة.', 422);
        }

        $newTableNumber = $request->input('table_number');

        // البحث عن الطاولة المستهدفة مع التحقق من الفرع
        $toTable = DiningTable::whereRaw('LOWER(table_number) = LOWER(?)', [$newTableNumber])
            ->where('branch_id', $order->branch_id)
            ->first();

        if (! $toTable) {
            \Log::warning('[ORDER TRANSFER] Target table not found', [
                'table_number' => $newTableNumber,
                'branch_id' => $order->branch_id
            ]);
            return $this->error('الطاولة المستهدفة غير موجودة في هذا الفرع.', 404);
        }

        if ($toTable->status !== 'AVAILABLE') {
            \Log::warning('[ORDER TRANSFER] Target table not available', [
                'table_id' => $toTable->id,
                'status' => $toTable->status
            ]);
            return $this->error('لا يمكن النقل لهذه الطاولة - ليست متاحة.', 422);
        }

        // منع النقل لنفس الطاولة
        if ($order->dining_table_id && $order->dining_table_id == $toTable->id) {
            \Log::warning('[ORDER TRANSFER] Same table transfer attempt', [
                'order_id' => $order->id,
                'table_id' => $toTable->id
            ]);
            return $this->error('لا يمكن النقل لنفس الطاولة.', 422);
        }

        try {
            DB::transaction(function () use ($order, $toTable) {
                // تحديد الطاولة القديمة - طريقة مباشرة وآمنة
                $oldTable = null;
                if ($order->dining_table_id) {
                    $oldTable = DiningTable::find($order->dining_table_id);
                }

                // حفظ بيانات الطاولة القديمة قبل التحرير
                $oldTableNumber = $oldTable?->table_number;
                $customerCount = $oldTable?->customer_count
                    ?? $order->customer_count
                    ?? 0;
                $seatedAt = $oldTable?->seated_at
                    ?? now();

                \Log::info('[ORDER TRANSFER] Starting transfer', [
                    'order_id' => $order->id,
                    'old_table_id' => $oldTable?->id,
                    'old_table_number' => $oldTableNumber,
                    'old_table_status' => $oldTable?->status,
                    'old_table_customer_count' => $customerCount,
                    'old_table_seated_at' => $seatedAt?->toIso8601String(),
                    'new_table_id' => $toTable->id,
                    'new_table_number' => $toTable->table_number,
                ]);

                // تحرير الطاولة القديمة تماماً - جعلها فارغة
                if ($oldTable) {
                    $oldTable->update([
                        'status' => 'AVAILABLE',
                        'current_order_id' => null,
                        'seated_at' => null,
                        'customer_count' => 0,
                    ]);

                    \Log::info('[ORDER TRANSFER] Old table cleared', [
                        'table_id' => $oldTable->id,
                        'table_number' => $oldTable->table_number,
                    ]);
                }

                // تحديث الطلب بالطاولة الجديدة وبياناتها
                $order->update([
                    'dining_table_id' => $toTable->id,
                    'table_number' => $toTable->table_number,
                    'customer_count' => $customerCount,
                    'seated_at' => $seatedAt,
                ]);

                // تحديث الطاولة الجديدة بكل بيانات الطاولة القديمة
                $toTable->update([
                    'status' => 'OCCUPIED',
                    'current_order_id' => $order->id,
                    'seated_at' => $seatedAt,
                    'customer_count' => $customerCount,
                    'last_order_at' => now(),
                ]);

                \Log::info('[ORDER TRANSFER] Transfer completed successfully', [
                    'order_id' => $order->id,
                    'from_table_id' => $oldTable?->id,
                    'from_table_number' => $oldTableNumber,
                    'to_table_id' => $toTable->id,
                    'to_table_number' => $toTable->table_number,
                    'transferred_customer_count' => $customerCount,
                    'transferred_seated_at' => $seatedAt?->toIso8601String(),
                ]);
            });

            return $this->success(
                'تم نقل الطلب بنجاح',
                new OrderResource($order->fresh()->load(['items.department', 'diningTable']))
            );
        } catch (\Throwable $e) {
            \Log::error('[ORDER TRANSFER] Failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error('فشل نقل الطلب: ' . $e->getMessage(), 500);
        }
    }

    public function journalEntry(Order $order): JsonResponse
    {
        $transaction = Transaction::with(['entries.account', 'entries.costCenter', 'branch', 'user'])
            ->where('source_type', Order::class)
            ->where('source_id', $order->id)
            ->where('type', 'sale')
            ->first();

        if (! $transaction) {
            return $this->error('لا يوجد قيد محاسبي لهذا الطلب بعد.', 404);
        }

        return $this->success('القيد المحاسبي', new TransactionResource($transaction));
    }

    /**
     * مزامنة تذاكر الإنتاج — يرسل فقط العناصر pending التي لا تملك ticketItem.
     * يُعيد استخدام التذاكر النشطة للقسم نفسه بدلاً من إنشاء تذاكر مكررة.
     */
    private function syncProductionTickets(Order $order): void
    {
        if (in_array($order->status, ['cancelled', 'paid', 'served'], true)) {
            return;
        }

        if ($order->items()->where('status', 'pending')->whereDoesntHave('ticketItem')->exists()) {
            app(\App\Services\Order\OrderConfirmationService::class)->release($order);
        }
    }

    private function createOrderItem(
        Order $order,
        int $itemId,
        float $quantity,
        ?float $unitPrice = null,
        ?string $notes = null,
        bool $isTakeaway = false,
    ): OrderItem {
        $item = Item::with('department')->findOrFail($itemId);

        $price = $unitPrice ?? $item->priceForBranch($order->branch_id);

        if ($price === null) {
            throw new \InvalidArgumentException('الصنف غير مفعّل أو بدون سعر في هذا الفرع.');
        }

        if (! $item->department_id) {
            throw new \InvalidArgumentException('الصنف غير مربوط بقسم — لا يمكن تقسيمه للمطبخ.');
        }

        return OrderItem::create([
            'order_id' => $order->id,
            'item_id' => $item->id,
            'department_id' => $item->department_id,
            'item_name' => $item->name,
            'item_name_ar' => $item->name_ar ?? $item->name,
            'price' => $price,
            'original_price' => $price,
            'final_price' => $price,
            'quantity' => $quantity,
            'total' => round($price * $quantity, 2),
            'status' => 'pending',
            'notes' => $notes,
            'is_takeaway' => $isTakeaway,
        ]);
    }

    /**
     * طباعة فاتورة الطلب
     */
    public function printInvoice(Order $order, OrderPrintingService $printingService): JsonResponse
    {
        $printerId = request('printer_id');

        if ($printerId) {
            $result = $printingService->printInvoiceById($order, (int) $printerId);
        } else {
            $result = $printingService->printInvoiceToCashier($order);
        }

        if ($result['success']) {
            return $this->success($result['message']);
        }

        return $this->error($result['message'], 500);
    }

    /**
     * طباعة تذاكر أقسام الإنتاج (ticket.blade.php) — كل قسم على طابعته.
     */
    public function printTickets(Order $order, OrderPrintingService $printingService): JsonResponse
    {
        $results = $printingService->printTickets($order);

        if (empty($results)) {
            return $this->error('لا توجد تذاكر أقسام للطباعة', 404);
        }

        $hasFailure = collect($results)->contains('success', false);
        $allMessage = $hasFailure
            ? 'تمت الطباعة جزئياً — بعض الأقسام لم تُطبع'
            : 'تمت طباعة جميع تذاكر الأقسام بنجاح';

        return $this->success($allMessage, $results);
    }

    /**
     * طباعة الطلب بالكامل — ذكية وديناميكية.
     *
     * تحدد تلقائياً:
     * - أصناف المطبخ → بون مطبخ (KOT)
     * - أصناف الكاشير → فاتورة كاشير
     *
     * تستقبل من الفرونت:
     * - device_type: 'POS' | 'WAITER_APP' | null (الكل)
     * - device_id: ID الجهاز | null (الكل)
     */
    public function printOrder(Order $order, OrderPrintingService $printingService): JsonResponse
    {
        $deviceType = request('device_type'); // 'POS' | 'WAITER_APP' | null
        $deviceId   = request('device_id');   // int | null
        $userId     = auth()->id();

        $results = $printingService->printOrder(
            $order,
            $userId,
            $deviceType,
            $deviceId ? (int) $deviceId : null
        );

        if (empty($results)) {
            return $this->error('لا توجد طابعات مخصصة لهذا الطلب', 404);
        }

        $hasFailure = collect($results)->contains('success', false);
        $allMessage = $hasFailure
            ? 'تمت الطباعة جزئياً — بعض الطابعات لم تستجب'
            : 'تمت الطباعة بنجاح على جميع الطابعات';

        return $this->success($allMessage, $results);
    }

    /**
     * طباعة فورية وتنفيذ — معالجة الطلب الفورية وطباعته للطابعات المفعلة
     */
    public function directPrint(Request $request, Order $order): JsonResponse
    {
        \Log::info('directPrint request data', [
            'all' => $request->all(),
            'cashier_device_id' => $request->input('cashier_device_id'),
            'items' => $request->input('items'),
            'order_id' => $order->id,
        ]);

        try {
            $request->validate([
                'cashier_device_id' => 'required|integer',
                'items' => 'nullable|array',
                'items.*.order_item_id' => 'required|integer|exists:order_items,id',
                'items.*.is_takeaway' => 'required|boolean',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('directPrint validation failed', ['errors' => $e->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'خطأ في البيانات المرسلة',
                'errors' => $e->errors(),
            ], 422);
        }

        // التحقق من وجود جهاز الكاشير — إذا لم يوجد نستخدم أول جهاز متاح للفرع
        $cashierDeviceId = (int) $request->cashier_device_id;
        if (!\App\Models\PosRegister::where('id', $cashierDeviceId)->exists()) {
            $fallback = \App\Models\PosRegister::where('branch_id', $order->branch_id)->first();
            if ($fallback) {
                $cashierDeviceId = $fallback->id;
            }
        }

        try {
            // حفظ حالة TW لكل صنف إذا تم تمريرها
            if ($request->has('items')) {
                foreach ($request->items as $itemData) {
                    \App\Models\OrderItem::where('id', $itemData['order_item_id'])
                        ->update(['is_takeaway' => $itemData['is_takeaway']]);
                }
            }

            $service = app(\App\Services\DirectPrintRoutingService::class);
            $result = $service->execute(
                $order->id,
                $cashierDeviceId
            );

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'print_jobs' => $result['print_jobs'],
                'printed_items_count' => $result['printed_items_count'],
            ]);
        } catch (\Throwable $e) {
            \Log::error('فشل في الطباعة الفورية للطلب #' . $order->id, [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء الطباعة الفورية',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

}
