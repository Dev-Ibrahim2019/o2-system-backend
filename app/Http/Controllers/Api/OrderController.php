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

            $branchId = $authUser->branch_id ?? $data['branch_id'] ?? \App\Models\Branch::value('id');

            $order = Order::create([
                'order_number' => Order::generateOrderNumber(),
                'dining_table_id' => $data['dining_table_id'] ?? null,
                'branch_id' => $branchId,
                'cashier_id' => $data['cashier_id'] ?? null,
                'order_type' => $data['order_type'],
                'status' => 'pending',
                'table_number' => $data['table_number'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'employee_id' => $data['employee_id'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
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
                        $row['notes'] ?? null
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
                // مسح الأصناف غير المطبوعة فقط (المحفوظة بـ is_printed_direct=true لا تُحذف)
                $order->items()
                    ->where('status', 'pending')
                    ->where('is_printed_direct', false)
                    ->delete();

                foreach ($request->items as $row) {
                    $this->createOrderItem(
                        $order,
                        (int) $row['item_id'],
                        (float) $row['quantity'],
                        isset($row['unit_price']) ? (float) $row['unit_price'] : null,
                        $row['notes'] ?? null
                    );
                }

                $this->syncProductionTickets($order);
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
                $data['notes'] ?? null
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
        if (! in_array($order->status, ['pending', 'pending_confirmation'], true)) {
            return $this->error('لا يمكن تأكيد هذا الطلب في حالته الحالية.', 422);
        }

        // تعديل: جلب العلاقة هنا مع الاستعلام الأساسي لتجنب مشكلة الـ with اللاحقة
        $unsentItems = $order->items()
            ->with('department')
            ->where('status', 'pending')
            ->get();

        if ($unsentItems->isEmpty()) {
            return $this->error('لا توجد عناصر جديدة لإرسالها.', 422);
        }

        DB::beginTransaction();
        try {
            // تعديل: التجميع مباشرة الآن بعد أن جلبنا العلاقات بالأعلى
            $itemsByDept = $unsentItems->groupBy('department_id');

            foreach ($itemsByDept as $deptId => $deptItems) {
                if (! $deptId) {
                    continue;
                }

                // البحث عن تذكرة نشطة موجودة للقسم نفسه في نفس الطلب
                $ticket = $order->tickets()
                    ->where('department_id', $deptId)
                    ->whereIn('status', ['pending', 'preparing'])
                    ->first();

                if (! $ticket) {
                    $ticket = ProductionTicket::create([
                        'order_id' => $order->id,
                        'department_id' => $deptId,
                        'ticket_number' => ProductionTicket::generateTicketNumber((int) $deptId),
                        'status' => 'pending',
                        'sent_at' => now(),
                        'notes' => $order->note,
                    ]);
                }

                foreach ($deptItems as $orderItem) {
                    // تجنب التكرار — لا تُضاف إذا لها ticketItem مسبقاً
                    if ($orderItem->ticketItem) {
                        continue;
                    }

                    ProductionTicketItem::create([
                        'production_ticket_id' => $ticket->id,
                        'order_item_id' => $orderItem->id,
                        'quantity' => (int) ceil((float) $orderItem->quantity),
                        'notes' => $orderItem->notes,
                        'status' => 'pending',
                    ]);

                    $orderItem->update(['sent_to_kitchen_at' => now()]);
                }
            }

            $order->update(['status' => 'confirmed']);

            // تحديث الطاولة إلى HAS_ORDER (عليها طلب)
            if ($order->dining_table_id) {
                $table = DiningTable::find($order->dining_table_id);
                if ($table) {
                    $table->update(['status' => 'HAS_ORDER']);
                }
            }

            DB::commit();

            return $this->success(
                'تم إرسال الطلب للأقسام',
                new OrderResource($order->fresh()->load([
                    'items.department',
                    'tickets.ticketItems.orderItem',
                    'tickets.department',
                ]))
            );
        } catch (\Throwable $e) {
            DB::rollBack();
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
                if (! $table && $order->table_number && $order->branch_id) {
                    $table = DiningTable::whereRaw('LOWER(table_number) = LOWER(?)', [$order->table_number])
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

        // فلترة العناصر غير المرحّلة فقط
        $unsentItems = $order->items()
            ->where('status', 'pending')
            ->whereDoesntHave('ticketItem')
            ->get();

        if ($unsentItems->isEmpty()) {
            return;
        }

        $itemsByDept = $unsentItems->groupBy('department_id');

        foreach ($itemsByDept as $deptId => $orderItems) {
            if (! $deptId) {
                continue;
            }

            // البحث عن تذكرة نشطة للقسم
            $ticket = $order->tickets()
                ->where('department_id', $deptId)
                ->whereIn('status', ['pending', 'preparing'])
                ->first();

            if (! $ticket) {
                $ticket = ProductionTicket::create([
                    'order_id' => $order->id,
                    'department_id' => $deptId,
                    'ticket_number' => ProductionTicket::generateTicketNumber((int) $deptId),
                    'status' => 'pending',
                    'sent_at' => now(),
                    'notes' => $order->note,
                ]);
            }

            foreach ($orderItems as $orderItem) {
                ProductionTicketItem::create([
                    'production_ticket_id' => $ticket->id,
                    'order_item_id' => $orderItem->id,
                    'quantity' => (int) ceil((float) $orderItem->quantity),
                    'notes' => $orderItem->notes,
                    'status' => 'pending',
                ]);

                $orderItem->update(['sent_to_kitchen_at' => now()]);
            }
        }

        // تحديث حالة الطلب إذا لم تكن في مرحلة متقدمة
        if (! in_array($order->status, ['confirmed', 'in_progress', 'ready', 'served'], true)) {
            $order->update(['status' => 'confirmed']);
        }
    }

    private function createOrderItem(
        Order $order,
        int $itemId,
        float $quantity,
        ?float $unitPrice = null,
        ?string $notes = null
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