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
use App\Models\CustomerNote;
use App\Models\Employee;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderActivityLog;
use App\Models\OrderItem;
use App\Models\ProductionTicket;
use App\Models\ProductionTicketItem;
use App\Models\Shift;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\Printing\OrderPrintingService;

class OrderController extends ApiController
{
    /**
     * هل يمكن للمستخدم تعديل طلب مغلق؟
     * المسموح: super-admin, branch-manager, accountant فقط
     */
    private function canEditClosedOrder(): bool
    {
        $user = auth()->user();
        if (!$user) return false;
        return $user->hasRole(['super-admin', 'branch-manager', 'accountant']);
    }

    /**
     * طلب كول سنتر مدفوع بالكامل ما بينعدّل من أي مسار (المبلغ المحوّل ثابت) — حتى من هاي الـendpoints
     * العامة، لأنه status تبعه بيضل pending/confirmed (الدفع بـ payment_status) فما كان يمسكه فحص 'paid' تحت.
     * نفس القاعدة بـ OrderAmendmentService لمسارات الكول سنتر نفسها.
     */
    private function paidCallCenterEditError(Order $order): ?JsonResponse
    {
        if ($order->source === 'call_center'
            && \App\Services\CallCenter\OrderFlowService::paymentState($order) === 'paid') {
            return $this->error(\App\Services\CallCenter\OrderAmendmentService::PAID_EDIT_MESSAGE, 422);
        }

        return null;
    }

    public function index(Request $request): JsonResponse
    {
        $query = Order::with(['items.department', 'tickets.department', 'cashier'])
            ->when($request->branch_id, fn($q) => $q->where('branch_id', $request->branch_id))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->statuses, fn($q) => $q->whereIn('status', explode(',', $request->statuses)))
            ->when($request->driver_id, fn($q) => $q->where('driver_id', $request->driver_id))
            ->when($request->date, fn($q) => $q->whereDate('created_at', $request->date))
            ->when($request->table_number, fn($q) => $q->where('table_number', $request->table_number))
            ->when($request->search, fn($q) => $q->where(function ($q2) use ($request) {
                $q2->where('order_number', 'like', "%{$request->search}%")
                    ->orWhere('customer_name', 'like', "%{$request->search}%")
                    ->orWhere('customer_phone', 'like', "%{$request->search}%");
            }))
            ->orderByDesc('id');

        if ($request->per_page) {
            $orders = $query->paginate($request->per_page);
            return $this->success('Orders fetched', [
                'data' => OrderResource::collection($orders),
                'meta' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                ],
            ]);
        }

        $orders = $query->get();
        return $this->success('Orders fetched', OrderResource::collection($orders));
    }

    /**
     * إنشاء طلب جديد (pending) — بدون أصناف؛ تُضاف عبر addItem
     */
    // Scope name for the optional order-creation idempotency guard below —
    // shares the existing IdempotencyRecord table/mechanism Call Center's
    // payment execution already uses (App\Services\Support\IdempotencyService),
    // not a new mechanism.
    private const IDEMPOTENCY_SCOPE = 'pos-order-create';

    public function store(StoreOrderRequest $request, \App\Services\Pos\PosCustomerLinkService $linker, \App\Services\Crm\IdentityConflictService $conflicts): JsonResponse
    {
        $data = $request->validated();

        // Fully backward compatible: only engages when the caller explicitly
        // sends idempotency_key. No key -> identical behavior to before this
        // change. Replays the original response instead of creating a
        // second Order for a repeated request (double-click, retry, etc.).
        $idempotencyKey = $data['idempotency_key'] ?? null;
        if ($idempotencyKey) {
            $existing = \App\Models\IdempotencyRecord::where([
                'scope' => self::IDEMPOTENCY_SCOPE,
                'key' => $idempotencyKey,
            ])->first();
            if ($existing && $existing->status === 'completed') {
                return response()->json($existing->response, $existing->response_status);
            }
        }

        $authUser = auth()->user();
        $branchId = $authUser->branch_id ?? $data['branch_id'] ?? null;

        // Resolve the typed name/phone into a real customer BEFORE opening the
        // order transaction. Deliberate: identity work must never be able to
        // roll back, slow down, or fail an order. The service is fail-open and
        // never throws — worst case it returns null and the order is a walk-in,
        // exactly as it behaved before this change.
        $link = $linker->resolve(
            customerId: $data['customer_id'] ?? null,
            name: $data['customer_name'] ?? null,
            phone: $data['customer_phone'] ?? null,
            branchId: $branchId,
            // Only the shared POS screen's own "محلي/فوري" toggle means
            // "عائلات"/"فوري" — Hospitality creates dine_in orders through
            // this same endpoint for an unrelated reason and must not be
            // tagged as the Families-hall cashier because of it.
            orderType: $authUser->hasRole('hospitality') ? null : ($data['order_type'] ?? null),
        );
        $data['customer_id'] = $link->customerId;

        DB::beginTransaction();
        try {
            $customer = isset($data['customer_id']) ? Customer::find($data['customer_id']) : null;

            if (! $branchId) {
                DB::rollBack();

                return $this->error('لا يوجد فرع محدد لحسابك — يجب ربط حسابك بفرع قبل إنشاء طلب.', 422);
            }

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
                'call_center_agent_id' => $data['call_center_agent_id'] ?? null,
                'shift_id' => $shift->id,
                'opened_by' => $authUser->id,
                'order_type' => $data['order_type'],
                'source' => $data['source'] ?? 'pos',
                'status' => ! empty($data['scheduled_at']) ? 'scheduled' : 'pending',
                'table_number' => $data['table_number'] ?? null,
                'customer_name' => $customer?->name ?? ($data['customer_name'] ?? null),
                // Keeps the typed name when it lost to the stored one — the
                // only record that this order was placed under a different
                // name, and what candidateOrders() matches on.
                'incoming_customer_name' => ($customer && $link->conflictCandidate)
                    ? $conflicts->incomingNameFor($customer, $link->conflictCandidate['name'])
                    : null,
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
                'tax_rate' => $data['tax_rate'] ?? 0,
                'tax_amount' => 0,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'payments' => $data['payments'] ?? null,
                'total' => 0,
            ]);

            // Ticket raised here, not inside the linker: it must point at the
            // order that caused it, and that id only exists now. afterCommit
            // is owned by queueIfConflicting().
            if ($link->conflictCandidate) {
                $conflicts->queueIfConflicting(
                    customer: $link->conflictCandidate['customer'],
                    incomingName: $link->conflictCandidate['name'],
                    incomingPhone: $link->conflictCandidate['phone'],
                    channel: 'pos_instant',
                    orderId: $order->id,
                );
            }

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

            // طلب كول سنتر انعمل من خانة فاضية محددة: بياخد هالخانة بدل أصغر خانة فاضية
            $slotNumber = null;
            if ($order->source === 'call_center') {
                $slots = app(\App\Services\CallCenter\OrderSlotService::class);
                $slotNumber = ! empty($data['slot_number'])
                    ? $slots->claim($order, (int) $data['slot_number'])
                    : \App\Models\OrderSlot::query()->where('order_id', $order->id)->whereNull('released_at')->value('slot_number');
            }

            DB::commit();

            $response = $this->withLinkStatus($this->success(
                'تم إنشاء الطلب',
                new OrderResource($order->load(['items.department', 'cashier'])),
                201
            ), $link->status);

            if ($order->source === 'call_center') {
                $payload = $response->getData(true);
                $payload['data']['slot_number'] = $slotNumber !== null ? (int) $slotNumber : null;
                $response->setData($payload);
            }

            if ($idempotencyKey) {
                \App\Models\IdempotencyRecord::updateOrCreate(
                    ['scope' => self::IDEMPOTENCY_SCOPE, 'key' => $idempotencyKey],
                    [
                        'user_id' => $authUser->id,
                        'request_hash' => hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE)),
                        'status' => 'completed',
                        'resource_type' => Order::class,
                        'resource_id' => $order->id,
                        'response' => $response->getData(true),
                        'response_status' => 201,
                    ]
                );
            }

            return $response;
        } catch (\InvalidArgumentException $e) {
            DB::rollBack();

            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->error('فشل إنشاء الطلب: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Surfaces the identity-resolution outcome alongside the order payload.
     *
     * Response-only and derived per request — nothing is stored, so no
     * migration and no new column. No client reads it yet; it exists so the
     * outcome is machine-readable for the first time.
     */
    private function withLinkStatus(JsonResponse $response, string $status): JsonResponse
    {
        $payload = $response->getData(true);
        $payload['customer_link_status'] = $status;

        return $response->setData($payload);
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
                'branch',
                'driver',
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

    public function update(
        UpdateOrderRequest $request,
        Order $order,
        \App\Services\Pos\PosCustomerLinkService $linker,
        \App\Services\Crm\IdentityConflictService $conflicts,
    ): JsonResponse {
        if ($blocked = $this->paidCallCenterEditError($order)) {
            return $blocked;
        }

        if (in_array($order->status, ['paid', 'cancelled'], true)) {
            if (!$this->canEditClosedOrder()) {
                return $this->error('لا يمكن تعديل طلب مغلق أو ملغى. الصلاحية مخصصة للمحاسب أو مدير الفرع فقط.', 422);
            }
        }

        // A deferred/held order reaches this method when the cashier resumes
        // it (useCart.ts takes the update() branch whenever an order already
        // exists), so the name/phone typed at that point never passed through
        // store() and had no identity resolution at all.
        //
        // Only when the order is still unlinked: an order already tied to a
        // customer is never re-resolved or re-pointed here.
        $validated = $request->safe()->all();
        $link = $order->customer_id !== null
            ? \App\Services\Pos\PosCustomerLink::linked((int) $order->customer_id)
            : $linker->resolve(
                customerId: $validated['customer_id'] ?? null,
                name: $validated['customer_name'] ?? $order->customer_name,
                phone: $validated['customer_phone'] ?? $order->customer_phone,
                branchId: $order->branch_id,
                // Same exclusion as store() above — see PosCustomerLinkService::resolve().
                orderType: $request->user()?->hasRole('hospitality') ? null : $order->order_type,
            );

        DB::beginTransaction();
        try {
            $order->update($request->safe()->except('items'));

            // Applied after the mass update so it wins over a null
            // customer_id that the payload may carry.
            if ($link->customerId !== null && $order->customer_id === null) {
                $order->update(['customer_id' => $link->customerId]);
            }

            if ($link->conflictCandidate) {
                $order->update([
                    'incoming_customer_name' => $conflicts->incomingNameFor(
                        $link->conflictCandidate['customer'],
                        $link->conflictCandidate['name'],
                    ),
                ]);

                $conflicts->queueIfConflicting(
                    customer: $link->conflictCandidate['customer'],
                    incomingName: $link->conflictCandidate['name'],
                    incomingPhone: $link->conflictCandidate['phone'],
                    channel: 'pos_instant',
                    orderId: $order->id,
                );
            }

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

                // Snapshot before the wipe — the sync below deletes every
                // unprinted pending item then recreates whatever the request
                // still lists, so "did anything actually change" (a new
                // item, a removed one, a quantity edit) is otherwise
                // unrecoverable by the time the order timeline asks: the old
                // rows are simply gone, no trace left. Keyed by item_id so a
                // quantity change on an existing item is diffable against
                // its old quantity, not read as delete+add of the same item.
                $beforeItems = $order->items()
                    ->where('status', 'pending')
                    ->where('is_printed_direct', false)
                    ->get(['item_id', 'item_name_ar', 'item_name', 'quantity'])
                    ->groupBy('item_id')
                    ->map(fn ($rows) => [
                        'name' => $rows->first()->item_name_ar ?: $rows->first()->item_name,
                        'quantity' => (float) $rows->sum('quantity'),
                    ]);

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

                $afterItems = $order->items()
                    ->where('status', 'pending')
                    ->where('is_printed_direct', false)
                    ->get(['item_id', 'item_name_ar', 'item_name', 'quantity'])
                    ->groupBy('item_id')
                    ->map(fn ($rows) => [
                        'name' => $rows->first()->item_name_ar ?: $rows->first()->item_name,
                        'quantity' => (float) $rows->sum('quantity'),
                    ]);

                $this->logItemsDiff($order, $beforeItems, $afterItems);

                if (! $request->boolean('skip_sync')) {
                    $this->syncProductionTickets($order);
                }
            }

            $order->recalculateTotals();

            DB::commit();

            return $this->withLinkStatus($this->success(
                'تم تحديث الطلب',
                new OrderResource($order->fresh()->load(['items.department', 'cashier']))
            ), $link->status);
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
        if ($blocked = $this->paidCallCenterEditError($order)) {
            return $blocked;
        }

        if (in_array($order->status, ['paid', 'cancelled'], true)) {
            if (!$this->canEditClosedOrder()) {
                return $this->error('لا يمكن تعديل تسعير طلب مغلق أو ملغى. الصلاحية مخصصة للمحاسب أو مدير الفرع فقط.', 422);
            }
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
        if ($blocked = $this->paidCallCenterEditError($order)) {
            return $blocked;
        }

        // يسمح الإضافة على: pending, pending_confirmation, confirmed, in_progress
        if (in_array($order->status, ['paid', 'cancelled', 'served', 'ready'])) {
            if (!$this->canEditClosedOrder()) {
                return $this->error('لا يمكن إضافة أصناف لهذا الطلب. الصلاحية مخصصة للمحاسب أو مدير الفرع فقط.', 422);
            }
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

            // هذا المسار (إضافة صنف واحد لطلب محفوظ مسبقًا) لم يكن يسجَّل بسجل
            // النشاطات إطلاقًا — فقط مزامنة الأصناف الجماعية بـ update() كانت
            // تُسجَّل. صفحة "سجل النشاط" (OrderController::activityLog) تقرأ من
            // order_activity_log فقط، فكانت أي إضافة صنف من هنا تختفي كليًا منها.
            $name = $orderItem->item_name_ar ?: $orderItem->item_name;
            $formattedQuantity = rtrim(rtrim(number_format($orderItem->quantity, 2), '0'), '.');
            OrderActivityLog::create([
                'order_id' => $order->id,
                'actor_id' => auth()->id(),
                'action_type' => 'item_added',
                'note' => "أُضيف: {$name} ×{$formattedQuantity}",
                'created_at' => now(),
            ]);

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
        if ($blocked = $this->paidCallCenterEditError($order)) {
            return $blocked;
        }

        if (in_array($order->status, ['paid', 'cancelled', 'served'])) {
            if (!$this->canEditClosedOrder()) {
                return $this->error('لا يمكن حذف أصناف من هذا الطلب. الصلاحية مخصصة للمحاسب أو مدير الفرع فقط.', 422);
            }
        }

        if ($orderItem->order_id !== $order->id) {
            return $this->error('الصنف لا ينتمي لهذا الطلب.', 422);
        }

        if ($orderItem->status !== 'pending') {
            return $this->error('لا يمكن حذف صنف أُرسل للمطبخ.', 422);
        }

        try {
            $name = $orderItem->item_name_ar ?: $orderItem->item_name;
            $quantity = (float) $orderItem->quantity;
            $orderItem->delete();
            $order->recalculateTotals();

            $formattedQuantity = rtrim(rtrim(number_format($quantity, 2), '0'), '.');
            OrderActivityLog::create([
                'order_id' => $order->id,
                'actor_id' => auth()->id(),
                'action_type' => 'item_removed',
                'note' => "حُذف: {$name} ×{$formattedQuantity}",
                'created_at' => now(),
            ]);

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
    // Resolved via app() rather than method-injected params: several regression tests
    // predating the call-center merge call this endpoint directly
    // (app(OrderController::class)->confirm($order)), bypassing the router's own
    // dependency injection — that calling convention must keep working.
    public function confirm(Order $order): JsonResponse
    {
        $confirmationService = app(\App\Services\CallCenter\OrderConfirmationService::class);
        $statusService = app(\App\Services\CallCenter\OrderStatusService::class);

        // محصور بطلبات الكول سنتر فقط (راجع ملاحظة cancel()/serve() المطابقة) — endpoint مشترك
        // مع الكاشير/الضيافة عبر useOrders.ts، وموظفي POS العاديين ما إلهم صلاحيات كول سنتر
        // ولا يجب يُطلب منهم ذلك لبدء تجهيز طلب محلي/فوري عادي ("-" اليدوي كما هو).
        if ($order->source === 'call_center' && ! $this->agentCan('call-center.change-order-status')) {
            return $this->error('لا تملك صلاحية تغيير حالة الطلب.', 403);
        }

        $fromStatus = $order->status;

        try {
            $order = $confirmationService->confirmOrder($order);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            \Log::error('فشل تأكيد الطلب #' . $order->id, [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error('فشل تأكيد الطلب: ' . $e->getMessage(), 500);
        }

        // action_type مخصّص (مو status_change العام) لأن confirmOrder() يُبقي status='paid' كما
        // هي عمدًا لطلب مدفوع مسبقًا (لا ينقلها لـ confirmed) — لولا هذا النوع المخصّص ما كان
        // "بدء التجهيز" رح يُسجَّل إطلاقًا لهذه الحالة رغم إنه فعليًا صار (تذاكر انبعتت للمطبخ).
        $statusService->logStatusChange($order, $fromStatus, $order->status, 'preparation_started');

        return $this->success('تم إرسال الطلب للأقسام', new OrderResource($order));
    }

    public function cancel(
        Request $request,
        Order $order,
        \App\Services\CallCenter\OrderStatusService $statusService,
        \App\Services\CallCenter\DeliveryAssignmentService $assignmentService,
    ): JsonResponse
    {
        // الفحص محصور بطلبات الكول سنتر فقط — هذا الـ endpoint مشترك مع الكاشير/الضيافة عبر
        // useOrders.ts وDeferredTables.tsx، وموظفي POS العاديين ما إلهم صلاحيات كول سنتر أصلاً
        // ولا يجب يُطلب منهم ذلك لإلغاء طلب محلي/فوري عادي.
        if ($order->source === 'call_center' && ! $this->agentCan('call-center.cancel-order')) {
            return $this->error('لا تملك صلاحية إلغاء الطلبات.', 403);
        }
        if (! in_array($order->status, ['pending', 'pending_confirmation', 'confirmed'], true)) {
            return $this->error('لا يمكن إلغاء هذا الطلب في حالته الحالية.', 422);
        }

        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:1000']])['reason'] ?? null;
        $fromStatus = $order->status;

        try {
            $statusService->assertTransition($order, 'cancelled');

            DB::transaction(function () use ($order, $reason, $assignmentService) {
                $order->update([
                    'status' => 'cancelled',
                    'cancellation_reason' => $reason,
                    'cancelled_at' => now(),
                ]);
                $order->tickets()->update(['status' => 'cancelled']);
                $order->items()->update(['status' => 'cancelled']);

                // تحرير سائق مُسنَد لو وُجد — غير قابل للحدوث فعليًا اليوم (الحالات المسموح
                // الإلغاء منها أعلاه تسبق تعيين السائق دومًا) لكنها شبكة أمان صحيحة لو تغيّرت
                // شروط الإلغاء مستقبلاً (القسم 14 بالبرومبت).
                if ($order->driver_id) {
                    $assignmentService->release($order, 'released');
                }

                // تحرير الطاولة إذا كانت مرتبطة
                if ($order->dining_table_id) {
                    $table = DiningTable::find($order->dining_table_id);
                    if ($table && $table->current_order_id == $order->id) {
                        $table->setAvailable();
                    }
                }
            });

            $statusService->logStatusChange($order->fresh(), $fromStatus, 'cancelled', 'status_change', $reason);

            return $this->success('تم إلغاء الطلب', new OrderResource($order->fresh()));
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('فشل إلغاء الطلب: ' . $e->getMessage(), 500);
        }
    }

    public function serve(Order $order, \App\Services\CallCenter\OrderStatusService $statusService): JsonResponse
    {
        // نفس النطاق المحصور بطلبات الكول سنتر فقط (راجع ملاحظة cancel() أعلاه) — endpoint مشترك
        // مع الكاشير/الضيافة، وموظفي POS العاديين ما إلهم صلاحيات كول سنتر لتسليم طلب محلي/فوري.
        if ($order->source === 'call_center' && ! $this->agentCan('call-center.change-order-status')) {
            return $this->error('لا تملك صلاحية تغيير حالة الطلب.', 403);
        }
        if (! in_array($order->status, ['ready', 'in_progress'], true)) {
            return $this->error('يجب أن يكون الطلب جاهزاً قبل التسليم.', 422);
        }

        $fromStatus = $order->status;

        try {
            $statusService->assertTransition($order, 'served');

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

            $statusService->logStatusChange($order->fresh(), $fromStatus, 'served');
            // طلبات محلي/فوري تُغلق مباشرة هون لو مدفوعة بالكامل أصلاً (لا خطوة توصيل وسيطة لها).
            $statusService->maybeAutoClose($order);

            return $this->success(
                'تم تسليم الطلب',
                new OrderResource($order->fresh()->load(['items.department', 'tickets.department']))
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('فشل تسليم الطلب: ' . $e->getMessage(), 500);
        }
    }

    /**
     * "الطلب جاهز" — مرحلة PREPARING → READY بنظام تتبع حالة الطلب (NEW→PREPARING→READY→
     * WITH_DRIVER→DELIVERED/PICKED_UP). confirmed وin_progress كلاهما PREPARING هون (راجع
     * ملاحظة OrderStatusService::CALL_CENTER_TRANSITIONS)؛ paid مُضافة لأن confirmOrder() يُبقي
     * طلبًا مدفوعًا مسبقًا على status='paid' بعد إرساله للمطبخ (لا ينقله لـ confirmed).
     */
    public function markReady(Order $order, \App\Services\CallCenter\OrderStatusService $statusService): JsonResponse
    {
        if ($order->source === 'call_center' && ! $this->agentCan('call-center.change-order-status')) {
            return $this->error('لا تملك صلاحية تغيير حالة الطلب.', 403);
        }
        if (! in_array($order->status, ['confirmed', 'in_progress', 'paid'], true)) {
            return $this->error('يجب أن يكون الطلب قيد التجهيز أولاً.', 422);
        }

        $fromStatus = $order->status;

        try {
            $statusService->assertTransition($order, 'ready');

            $order->update(['status' => 'ready']);
            $order->tickets()->whereIn('status', ['pending', 'preparing'])->update(['status' => 'ready']);
            $order->items()->whereIn('status', ['pending', 'preparing'])->update(['status' => 'ready']);

            $fresh = $order->fresh();
            $statusService->logStatusChange($fresh, $fromStatus, 'ready');

            return $this->success(
                'الطلب جاهز',
                new OrderResource($fresh->load(['items.department', 'tickets.department']))
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('فشل تحديث حالة الطلب: ' . $e->getMessage(), 500);
        }
    }

    /**
     * تعيين موظف توصيل لطلب جاهز (order_type=delivery فقط) — منطق التحقق/الذرّية/سجل التاريخ
     * بالكامل بـ DeliveryAssignmentService (يحمي من تعارض موظفَي كول سنتر على نفس السائق).
     */
    public function assignDelivery(Request $request, Order $order, \App\Services\CallCenter\DeliveryAssignmentService $assignmentService): JsonResponse
    {
        if (! $this->agentCan('call-center.assign-driver')) {
            return $this->error('لا تملك صلاحية تعيين موظف توصيل.', 403);
        }

        $data = $request->validate([
            'driver_id' => ['required', 'integer', 'exists:employees,id'],
        ]);

        try {
            $order = $assignmentService->assign($order, (int) $data['driver_id']);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success('تم تعيين موظف التوصيل', new OrderResource($order));
    }

    /** تغيير السائق المُسنَد لطلب OUT_FOR_DELIVERY — يحافظ على تاريخ التعيين القديم بدل حذفه. */
    public function changeDriver(Request $request, Order $order, \App\Services\CallCenter\DeliveryAssignmentService $assignmentService): JsonResponse
    {
        if (! $this->agentCan('call-center.assign-driver')) {
            return $this->error('لا تملك صلاحية تغيير موظف التوصيل.', 403);
        }

        $data = $request->validate([
            'driver_id' => ['required', 'integer', 'exists:employees,id'],
        ]);

        try {
            $order = $assignmentService->reassign($order, (int) $data['driver_id']);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success('تم تغيير السائق', new OrderResource($order));
    }

    /** إلغاء تعيين السائق (Unassign) — يرجّع الطلب لحالة "جاهز" بدون سائق، محمي بنفس صلاحية تعديل الطلبات المغلقة */
    public function unassignDelivery(Order $order, \App\Services\CallCenter\DeliveryAssignmentService $assignmentService): JsonResponse
    {
        if (! $this->agentCan('call-center.assign-driver')) {
            return $this->error('لا تملك صلاحية إلغاء تعيين موظف التوصيل.', 403);
        }

        try {
            $order = $assignmentService->unassign($order);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success('تم إلغاء تعيين السائق', new OrderResource($order));
    }

    /** تسليم طلب توصيل مُسنَد لسائق (OUT_FOR_DELIVERY → DELIVERED) */
    public function markDelivered(
        Order $order,
        \App\Services\CallCenter\OrderStatusService $statusService,
        \App\Services\CallCenter\DeliveryAssignmentService $assignmentService,
    ): JsonResponse
    {
        if (! $this->agentCan('call-center.assign-driver')) {
            return $this->error('لا تملك صلاحية تسليم طلبات التوصيل.', 403);
        }
        if ($order->status !== 'OUT_FOR_DELIVERY') {
            return $this->error('لا يمكن تسليم الطلب قبل تعيينه لموظف توصيل.', 422);
        }

        try {
            $statusService->assertTransition($order, 'DELIVERED');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $order->update([
            'status' => 'DELIVERED',
            'delivered_at' => now(),
        ]);

        // اكتمل دور السائق التشغيلي بالتسليم — يُحرَّر عبء العمل لديه فورًا (لا ينتظر closed).
        $assignmentService->release($order, 'completed');

        $statusService->logStatusChange($order->fresh(), 'OUT_FOR_DELIVERY', 'DELIVERED');
        $statusService->maybeAutoClose($order);

        return $this->success(
            'تم تسليم الطلب',
            new OrderResource($order->fresh()->load(['items.department', 'driver']))
        );
    }

    /** إعادة فتح طلب مغلق — إجراء إداري صريح، يتطلب سبب، محمي بنفس صلاحية تعديل الطلبات المغلقة */
    public function reopen(Request $request, Order $order, \App\Services\CallCenter\OrderStatusService $statusService): JsonResponse
    {
        if (! $this->canEditClosedOrder()) {
            return $this->error('إعادة فتح الطلب تحتاج صلاحية محاسب/مدير فرع.', 403);
        }

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        try {
            $reopened = $statusService->reopen($order, $data['reason']);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success('تم إعادة فتح الطلب', new OrderResource($reopened));
    }

    /**
     * إتمام قسري (Force Complete) — استثناء إداري لحالات حدّية (القسم 7)، يحرّر السائق المُسنَد
     * لو وُجد كجزء من نفس المعاملة الذرّية. يتطلب سبب إلزامي ولا يتخطى شرط الدفع الكامل أبدًا.
     */
    public function forceComplete(
        Request $request,
        Order $order,
        \App\Services\CallCenter\OrderStatusService $statusService,
        \App\Services\CallCenter\DeliveryAssignmentService $assignmentService,
    ): JsonResponse
    {
        // مستويان: manual-complete-order يعطي نفس صلاحية الإتمام القسري الإدارية الكاملة (أي حالة
        // نشطة)؛ complete-order وحدها أضيق — تعمل فقط لطلب وصل بالفعل DELIVERED (السائق أكّد
        // التسليم)، لا تتيح تخطي التسلسل الطبيعي كليًا مثل الصلاحية الأولى.
        $hasFullAccess = $this->agentCan('call-center.manual-complete-order');
        $hasBasicAccess = $this->agentCan('call-center.complete-order') && $order->status === 'DELIVERED';

        if (! $hasFullAccess && ! $hasBasicAccess) {
            return $this->error('لا تملك صلاحية إتمام هذا الطلب في حالته الحالية.', 403);
        }

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        try {
            $completed = DB::transaction(function () use ($order, $data, $statusService, $assignmentService) {
                if ($order->driver_id) {
                    $assignmentService->release($order, 'completed');
                }

                return $statusService->forceComplete($order, $data['reason']);
            });
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success('تم إتمام الطلب', new OrderResource($completed->load(['items.department', 'driver'])));
    }

    /** سجل نشاط الطلب (Timeline) — كل تغييرات الحالة/الدفع/تعيين السائق مرتبة زمنيًا */
    public function activityLog(Order $order): JsonResponse
    {
        $log = \App\Models\OrderActivityLog::where('order_id', $order->id)
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($entry) => [
                'id' => $entry->id,
                'action_type' => $entry->action_type,
                'from_status' => $entry->from_status,
                'to_status' => $entry->to_status,
                'note' => $entry->note,
                'actor' => $entry->actor?->name,
                'created_at' => $entry->created_at?->toIso8601String(),
            ]);

        return $this->success('سجل نشاط الطلب', $log);
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
    ): OrderItem {
        $item = Item::with('department')->findOrFail($itemId);

        $price = $unitPrice ?? $item->priceForBranch($order->branch_id);

        if ($price === null) {
            throw new \InvalidArgumentException('الصنف غير مفعّل أو بدون سعر في هذا الفرع.');
        }

        if (! $item->department_id) {
            throw new \InvalidArgumentException('الصنف غير مربوط بقسم — لا يمكن تقسيمه للمطبخ.');
        }

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'created_by' => auth()->id(),
            // order_items keeps $timestamps = false (no updated_at column
            // exists) — created_at must be set explicitly or every item ever
            // added shows in the order timeline as having happened at the
            // order's original opening time, indistinguishable from items
            // added later to an already-saved order.
            'created_at' => now(),
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

        // A note typed on an item ("بدون زيتون") is customer preference
        // data, not just kitchen instructions for this one order — mirrored
        // onto the customer's own CRM notes (customer_notes, order_id-linked)
        // the same way an order-level note already is in
        // CallCenterOrderCreationService::create(), so it shows up on their
        // profile for the next call/visit regardless of which channel typed
        // it. Only when the order is actually linked to a real customer.
        if (filled($notes) && $order->customer_id) {
            CustomerNote::create([
                'customer_id' => $order->customer_id,
                'order_id' => $order->id,
                'content' => "{$item->name}: {$notes}",
                'type' => 'preference',
                'created_by' => auth()->id(),
            ]);
        }

        return $orderItem;
    }

    /**
     * يقارن أصناف الطلب قبل/بعد مزامنة update() (التي تمسح كل الأصناف غير
     * المطبوعة وتُعيد إنشاءها من الصفر) وتُسجّل الفرق الحقيقي — إضافة صنف،
     * حذفه، أو تغيير كميته — بسجل نشاطات الطلب. بدون هذا، أي تعديل على
     * طلب محفوظ (إضافة/حذف/تغيير كمية) كان يختفي كليًا من السجل لأن آلية
     * المزامنة نفسها لا تُبقي أي أثر للحالة القديمة.
     *
     * @param  \Illuminate\Support\Collection<int, array{name: string, quantity: float}>  $before
     * @param  \Illuminate\Support\Collection<int, array{name: string, quantity: float}>  $after
     */
    private function logItemsDiff(Order $order, $before, $after): void
    {
        $fmt = fn (float $q) => rtrim(rtrim(number_format($q, 2), '0'), '.');

        $added = [];
        $removed = [];
        $changed = [];

        foreach ($after as $itemId => $row) {
            if (! $before->has($itemId)) {
                $added[] = "{$row['name']} ×{$fmt($row['quantity'])}";
            } elseif ((float) $before[$itemId]['quantity'] !== (float) $row['quantity']) {
                $changed[] = "{$row['name']} ({$fmt($before[$itemId]['quantity'])} ← {$fmt($row['quantity'])})";
            }
        }

        foreach ($before as $itemId => $row) {
            if (! $after->has($itemId)) {
                $removed[] = "{$row['name']} ×{$fmt($row['quantity'])}";
            }
        }

        if (! $added && ! $removed && ! $changed) {
            return;
        }

        $parts = [];
        if ($added) $parts[] = 'أُضيف: ' . implode('، ', $added);
        if ($removed) $parts[] = 'حُذف: ' . implode('، ', $removed);
        if ($changed) $parts[] = 'تغيّرت الكمية: ' . implode('، ', $changed);

        OrderActivityLog::create([
            'order_id' => $order->id,
            'actor_id' => auth()->id(),
            'action_type' => 'items_updated',
            'note' => implode(' — ', $parts),
            'created_at' => now(),
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
            // تسجيل من طبع الفاتورة
            $order->update([
                'printed_by' => auth()->id(),
                'printed_at' => now(),
            ]);

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
