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
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductionTicket;
use App\Models\ProductionTicketItem;
use App\Models\Shift;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Services\Accounting\TransactionPostingService;
use App\Jobs\PrintInvoiceJob;
use App\Jobs\PrintTicketsJob;
use App\Jobs\PrintOrderJob;

class OrderController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        // active=1 → الطلبات المفتوحة فقط (بدون paid/cancelled). يستخدمه الـ POS/الضيافة
        // عند جلب طلبات طاولة معيّنة: كان يسحب كل تاريخ الطاولة (مئات السجلات مع
        // علاقاتها) 3 مرات في كل ضغطة "إرسال الطلب" — بطيء جداً. الفلترة على مستوى
        // الـ DB + سقف اختياري يقصّرها بشكل كبير.
        $isActiveOnly = $request->boolean('active');

        $eager = $isActiveOnly
            ? ['items.department', 'tickets.department', 'cashier']
            : ['items.department', 'tickets.department', 'cashier', 'invoice.payments'];

        $orders = Order::with($eager)
            ->when($request->branch_id, fn($q) => $q->where('branch_id', $request->branch_id))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($isActiveOnly, fn($q) => $q->whereNotIn('status', ['paid', 'cancelled']))
            ->when($request->date, fn($q) => $q->whereDate('created_at', $request->date))
            ->when($request->table_number, fn($q) => $q->where('table_number', $request->table_number))
            ->orderByDesc('id')
            ->when($request->integer('limit') > 0, fn($q) => $q->limit($request->integer('limit')))
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

            // التأكد من وجود shift مفتوح للفرع (إنشاء تلقائي إذا لم يوجد)
            $shift = Shift::getOrCreateToday($branchId, $authUser->id);

            // التحقق من أن السنة المالية مرتبطة بالـ shift ليست مغلقة
            if ($shift->fiscal_year_id) {
                $fiscalYear = FiscalYear::find($shift->fiscal_year_id);
                if ($fiscalYear && $fiscalYear->status === 'closed') {
                    DB::rollBack();
                    return $this->error('السنة المالية مغلقة. لا يمكن إنشاء طلبات في هذه الفترة.', 422);
                }
            }

            $order = Order::create([
                'order_number' => Order::generateOrderNumber(),
                'dining_table_id' => $data['dining_table_id'] ?? null,
                'branch_id' => $branchId,
                'cashier_id' => $data['cashier_id'] ?? null,
                'shift_id' => $shift->id,
                'opened_by' => $authUser->id,
                'order_type' => $data['order_type'],
                'status' => 'pending',
                'table_number' => $data['table_number'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'customer_mobile' => $data['customer_mobile'] ?? null,
                'customer_address' => $data['customer_address'] ?? null,
                'customer_notes' => $data['customer_notes'] ?? null,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'currency' => $data['currency'] ?? 'ILS',
                'exchange_rate' => $data['exchange_rate'] ?? 1,
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
                        $row['notes'] ?? null,
                        $row['is_takeaway'] ?? false,
                        $row['is_complimentary'] ?? false
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
                // كل الأصناف المطبوعة مسبقاً بهذا الطلب — استعلام واحد بدل استعلام لكل صنف بالحلقة
                $printedDirectItems = $order->items()
                    ->where('is_printed_direct', true)
                    ->get()
                    ->keyBy('item_id');

                // تحديث is_takeaway/is_complimentary/الملاحظة للأصناف المطبوعة (المحفوظة مسبقاً)
                foreach ($request->items as $row) {
                    $existingItem = $printedDirectItems->get($row['item_id']);

                    if ($existingItem) {
                        $existingItem->update([
                            'is_takeaway' => $row['is_takeaway'] ?? false,
                            'is_complimentary' => $row['is_complimentary'] ?? false,
                            'notes' => $row['notes'] ?? $existingItem->notes,
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
                    if ($printedDirectItems->has($row['item_id'])) {
                        continue;
                    }

                    $this->createOrderItem(
                        $order,
                        (int) $row['item_id'],
                        (float) $row['quantity'],
                        isset($row['unit_price']) ? (float) $row['unit_price'] : null,
                        $row['notes'] ?? null,
                        $row['is_takeaway'] ?? false,
                        $row['is_complimentary'] ?? false
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
                $data['is_takeaway'] ?? false,
                $data['is_complimentary'] ?? false
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

    /**
     * إضافة عدة أصناف دفعة واحدة — إدراج كلها في معاملة واحدة مع إعادة حساب
     * الإجماليات مرة واحدة فقط. يستبدل حلقة addItem صنف-صنف (طلب HTTP لكل صنف +
     * recalculateTotals لكل صنف) التي كانت تُبطئ "إرسال الطلب" بالضيافة كثيراً.
     */
    public function addItemsBatch(Request $request, Order $order): JsonResponse
    {
        if (in_array($order->status, ['paid', 'cancelled', 'served', 'ready'])) {
            return $this->error('لا يمكن إضافة أصناف لهذا الطلب.', 422);
        }

        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:items,id',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.notes' => 'nullable|string',
            'items.*.is_takeaway' => 'nullable|boolean',
            'items.*.is_complimentary' => 'nullable|boolean',
        ]);

        DB::beginTransaction();
        try {
            $added = [];
            foreach ($data['items'] as $row) {
                $added[] = $this->createOrderItem(
                    $order,
                    (int) $row['item_id'],
                    (float) $row['quantity'],
                    isset($row['unit_price']) ? (float) $row['unit_price'] : null,
                    $row['notes'] ?? null,
                    $row['is_takeaway'] ?? false,
                    $row['is_complimentary'] ?? false,
                );
            }

            $order->recalculateTotals();

            DB::commit();

            return $this->success(
                'تمت إضافة الأصناف',
                [
                    'order' => new OrderResource($order->fresh()->load(['items.department', 'cashier'])),
                    'added_count' => count($added),
                ],
                201
            );
        } catch (\InvalidArgumentException $e) {
            DB::rollBack();

            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->error('فشل إضافة الأصناف: ' . $e->getMessage(), 500);
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
        // 'confirmed' مسموحة هون كمان: لما تنضاف جولة جديدة من الأصناف على
        // نفس الطلب الموجود (بدل ما تنعمل Order منفصلة لكل جولة)، الطلب
        // أصلاً بيكون status='confirmed' من الجولة الأولى، وبدنا نرسل بس
        // الأصناف الجديدة (status='pending' على مستوى الصنف) للمطبخ —
        // المنطق تحت أصلاً بيفلتر على مستوى الصنف مش الطلب ككل.
        if (! in_array($order->status, ['pending', 'pending_confirmation', 'confirmed'], true)) {
            return $this->error('لا يمكن تأكيد هذا الطلب في حالته الحالية.', 422);
        }

        // تعديل: جلب العلاقة هنا مع الاستعلام الأساسي لتجنب مشكلة الـ with اللاحقة
        // ticketItem محمّلة مسبقاً أيضاً لتفادي استعلام منفصل لكل صنف بحلقة foreach بالأسفل
        $unsentItems = $order->items()
            ->with(['department', 'ticketItem'])
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

                    $orderItem->update([
                        'sent_to_kitchen_at' => now(),
                        'is_printed_direct' => true,
                    ]);
                }
            }

            $order->update(['status' => 'confirmed']);

            // تحديث الطاولة إلى OCCUPIED (عليها طلب)
            if ($order->dining_table_id) {
                $table = DiningTable::find($order->dining_table_id);
                if ($table) {
                    $table->update(['status' => 'OCCUPIED']);
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

    /**
     * هل بقي للطاولة أي طلب نشط آخر (جولة تانية لسا مفتوحة) غير الطلب الحالي؟
     * نفس القائمة المستخدمة بـ transfer() — لتفادي تحرير طاولة عليها جولة لسا شغالة.
     */
    private function tableHasOtherActiveOrders(DiningTable $table, Order $order): bool
    {
        return Order::where(function ($q) use ($table) {
                $q->where('dining_table_id', $table->id)
                  ->orWhere('table_number', $table->table_number);
            })
            ->whereIn('status', ['pending', 'pending_confirmation', 'confirmed', 'in_progress', 'ready'])
            ->where('id', '!=', $order->id)
            ->exists();
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

                // تحرير الطاولة إذا كانت مرتبطة، وفقط إذا ما بقي عليها طلب نشط تاني
                if ($order->dining_table_id) {
                    $table = DiningTable::find($order->dining_table_id);
                    if ($table && $table->current_order_id == $order->id && ! $this->tableHasOtherActiveOrders($table, $order)) {
                        $table->setAvailable();
                    }
                }
            });

            return $this->success('تم إلغاء الطلب', new OrderResource($order->fresh()));
        } catch (\Throwable $e) {
            return $this->error('فشل إلغاء الطلب: ' . $e->getMessage(), 500);
        }
    }

    /**
     * عكس/إلغاء بيع تم تحصيله بالكامل (Void) — يختلف عن cancel():
     * cancel() تلغي طلب لسا ما تحاسب (pending/confirmed)، أما void() تعكس
     * بيع مدفوع فعلياً: بترجع قيد اليومية بعكس مدين/دائن، تلغي الفاتورة،
     * وتحوّل الطلب لملغي — مع سبب إلزامي للتدقيق.
     */
    public function void(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        if ($order->status !== 'paid') {
            return $this->error(
                'لا يمكن استخدام Void إلا على طلب مدفوع بالكامل. للطلبات غير المدفوعة استخدم الإلغاء العادي.',
                422
            );
        }

        try {
            $order = DB::transaction(function () use ($order, $data) {
                $transaction = $order->journalEntry();
                if ($transaction && $transaction->status === 'posted' && ! $transaction->reversal()->exists()) {
                    app(TransactionPostingService::class)->reverse($transaction, $data['reason']);
                }

                $invoice = $order->invoice;
                if ($invoice && $invoice->status !== 'cancelled') {
                    // ما بنمسح الدفعات (Payment) — سجل تدقيقي لما تحصّل فعلياً،
                    // والعكس المحاسبي أعلاه هو يلي بيصحح الأرصدة.
                    $invoice->update(['status' => 'cancelled']);
                }

                $order->update([
                    'status' => 'cancelled',
                    'cancellation_reason' => $data['reason'],
                    'cancelled_at' => now(),
                ]);

                return $order;
            });

            return $this->success(
                'تم عكس/إلغاء البيع بنجاح',
                new OrderResource($order->fresh(['items', 'invoice.payments']))
            );
        } catch (\Throwable $e) {
            \Log::error('فشل Void للطلب #' . $order->id, [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return $this->error('فشل إلغاء/عكس البيع: ' . $e->getMessage(), 500);
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

                if ($table && ! $this->tableHasOtherActiveOrders($table, $order)) {
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

        // السماح بالنقل للطاولة المتاحة أو المشغولة (لكن ليس نفس الطاولة)
        if ($toTable->status !== 'AVAILABLE' && $toTable->status !== 'OCCUPIED') {
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
                // تحديد الطاولة القديمة
                $oldTable = null;
                if ($order->dining_table_id) {
                    $oldTable = DiningTable::find($order->dining_table_id);
                }

                $oldTableNumber = $oldTable?->table_number;
                $customerCount = $oldTable?->customer_count ?? $order->customer_count ?? 0;
                $seatedAt = $oldTable?->seated_at ?? now();

                // تحرير الطاولة القديمة فقط إذا لم يعد فيها أي طلبات نشطة
                if ($oldTable) {
                    $remainingOrders = Order::where(function ($q) use ($oldTable) {
                            $q->where('dining_table_id', $oldTable->id)
                              ->orWhere('table_number', $oldTable->table_number);
                        })
                        ->whereIn('status', ['pending', 'pending_confirmation', 'confirmed', 'in_progress', 'ready'])
                        ->where('id', '!=', $order->id)
                        ->count();

                    if ($remainingOrders === 0) {
                        $oldTable->update([
                            'status' => 'AVAILABLE',
                            'current_order_id' => null,
                            'seated_at' => null,
                            'customer_count' => 0,
                        ]);
                    }
                }

                // تحديث الطلب بالطاولة الجديدة
                $order->update([
                    'dining_table_id' => $toTable->id,
                    'table_number' => $toTable->table_number,
                    'customer_count' => $customerCount,
                    'seated_at' => $seatedAt,
                ]);

                // تحديث الطاولة الجديدة - فقط إذا لم تكن مشغولة بالفعل
                if ($toTable->status === 'AVAILABLE') {
                    $toTable->update([
                        'status' => 'OCCUPIED',
                        'current_order_id' => $order->id,
                        'seated_at' => $seatedAt,
                        'customer_count' => $customerCount,
                        'last_order_at' => now(),
                    ]);
                } else {
                    // الطاولة مشغولة بالفعل - فقط نضيف order_id وأخر وقت طلب
                    $toTable->update([
                        'last_order_at' => now(),
                    ]);
                }
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

                $orderItem->update([
                    'sent_to_kitchen_at' => now(),
                    'is_printed_direct' => true,
                ]);
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
        ?string $notes = null,
        bool $isTakeaway = false,
        bool $isComplimentary = false,
    ): OrderItem {
        $item = Item::with('department')->findOrFail($itemId);
        $catalogPrice = $item->priceForBranch($order->branch_id);

        // نثق بسعر الفرع من الكتالوج دايماً، إلا إذا المستخدم فعلياً معه صلاحية
        // تعديل السعر (adjust-order-total) وبعت سعر مختلف عمداً. بدون هالفحص،
        // أي طلب addItem/store كان يقدر يمرر أي unit_price كيفما كان (حتى 0.01)
        // ويتقبل كما هو — الفرونت اند العادي أصلاً بيبعت نفس سعر الكتالوج دايماً،
        // فهاد الفحص ما بيأثر على الاستخدام الطبيعي إطلاقاً.
        $price = $catalogPrice;
        if ($unitPrice !== null && (float) $unitPrice !== (float) $catalogPrice) {
            $user = auth()->user();
            if ($user && method_exists($user, 'can') && $user->can('adjust-order-total')) {
                $price = $unitPrice;
            }
        }

        if ($price === null) {
            throw new \InvalidArgumentException('الصنف غير مفعّل أو بدون سعر في هذا الفرع.');
        }

        if (! $item->department_id) {
            throw new \InvalidArgumentException('الصنف غير مربوط بقسم — لا يمكن تقسيمه للمطبخ.');
        }

        return OrderItem::create([
            'order_id' => $order->id,
            'created_by' => auth()->id(),
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
            'is_complimentary' => $isComplimentary,
        ]);
    }

    /**
     * طباعة فاتورة الطلب — تُنفَّذ بالخلفية عبر Queue Job (Browsershot يشغّل
     * Chrome كامل، لا يجوز حجز الـ request عليه). الاستجابة هون تأكيد إرسال
     * أمر الطباعة فقط، مش تأكيد نجاح الطباعة الفعلي على الطابعة.
     */
    public function printInvoice(Order $order): JsonResponse
    {
        // قفل قصير جداً يمنع بس تكرار فعلي بنفس اللحظة (ضغطة مزدوجة/طلب
        // مكرر من الواجهة خلال نفس الثانية) — مش مقصود يمنع إعادة طباعة
        // متعمدة بعد شوي، فلازم يضل قصير.
        $lock = Cache::lock("print-invoice:{$order->id}", 3);

        if (!$lock->get()) {
            return $this->success('أمر الطباعة قيد التنفيذ لهذا الطلب بالفعل');
        }

        $printerId = request('printer_id');

        // mode: 'all' | 'merged' | 'departments' | 'fawri' (فاتورة كل قسم على طابعة الكاشير)
        $mode = request('mode', 'all');
        if (!in_array($mode, ['all', 'merged', 'departments', 'fawri'], true)) {
            $mode = 'all';
        }

        // dispatchAfterResponse: تنفّذ بنفس عملية PHP بعد إرسال الرد للمتصفّح،
        // بدون الاعتماد على queue worker خارجي (كان لازم يضل شغّال وإلا ما بتطبع).
        PrintInvoiceJob::dispatchAfterResponse(
            $order,
            $printerId ? (int) $printerId : null,
            auth()->id(),
            $mode,
        );

        return $this->success('تم إرسال أمر طباعة الفاتورة');
    }

    /**
     * طباعة تذاكر أقسام الإنتاج (ticket.blade.php) — كل قسم على طابعته.
     * تُنفَّذ بالخلفية عبر Queue Job.
     */
    public function printTickets(Order $order): JsonResponse
    {
        PrintTicketsJob::dispatchAfterResponse($order);

        return $this->success('تم إرسال أوامر طباعة تذاكر الأقسام');
    }

    /**
     * طباعة الطلب بالكامل — ذكية وديناميكية. تُنفَّذ بالخلفية عبر Queue Job.
     *
     * تحدد تلقائياً:
     * - أصناف المطبخ → بون مطبخ (KOT)
     * - أصناف الكاشير → فاتورة كاشير
     *
     * تستقبل من الفرونت:
     * - device_type: 'POS' | 'WAITER_APP' | null (الكل)
     * - device_id: ID الجهاز | null (الكل)
     */
    public function printOrder(Order $order): JsonResponse
    {
        $deviceType = request('device_type'); // 'POS' | 'WAITER_APP' | null
        $deviceId   = request('device_id');   // int | null
        $userId     = auth()->id();

        PrintOrderJob::dispatchAfterResponse(
            $order,
            $userId,
            $deviceType,
            $deviceId ? (int) $deviceId : null,
        );

        return $this->success('تم إرسال أوامر الطباعة');
    }

    /**
     * طباعة فورية وتنفيذ — معالجة الطلب الفورية وطباعته للطابعات المفعلة
     */
    public function directPrint(Request $request, Order $order): JsonResponse
    {
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
            // حفظ حالة TW لكل صنف إذا تم تمريرها — تحديث دفعي واحد لكل قيمة
            // (true/false) بدل استعلام UPDATE منفصل لكل صنف بالحلقة.
            if ($request->has('items')) {
                foreach (collect($request->items)->groupBy('is_takeaway') as $isTakeaway => $rows) {
                    \App\Models\OrderItem::whereIn('id', $rows->pluck('order_item_id'))
                        ->update(['is_takeaway' => (bool) $isTakeaway]);
                }
            }

            // الطباعة الفعلية (Browsershot + إرسال للطابعة) بطيئة (ثواني)،
            // فتُنفَّذ بالخلفية عبر Queue Job بدل حجز الـ request عليها.
            // وقت ضغط "تنفيذ وطباعة" = وقت إغلاق الفاتورة الفوري — يُطبع على التيكيت
            // (نلتقطه هون لأن التيكيت يُرسم بالخلفية وقد يتأخر ثوانٍ عن هذه اللحظة).
            \App\Jobs\DirectPrintJob::dispatchAfterResponse(
                $order->id,
                $cashierDeviceId,
                auth()->id(),
                now()->toIso8601String(),
            );

            return response()->json([
                'success' => true,
                'message' => 'تم إرسال أمر الطباعة الفورية',
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