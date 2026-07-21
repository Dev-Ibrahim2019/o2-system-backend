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
use App\Models\OrderCustomerExperience;
use App\Models\OrderItem;
use App\Models\ProductionTicket;
use App\Models\ProductionTicketItem;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Services\Printing\OrderPrintingService;

class OrderController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $orders = Order::with(['items.department', 'tickets.department', 'cashier', 'assembler', 'deliveryDriver'])
            ->when($request->branch_id, fn($q) => $q->where('branch_id', $request->branch_id))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->date, fn($q) => $q->whereDate('created_at', $request->date))
            ->orderByDesc('id')
            ->get();

        return $this->success('Orders fetched', OrderResource::collection($orders));
    }

    /**
     * ط¥ظ†ط´ط§ط، ط·ظ„ط¨ ط¬ط¯ظٹط¯ (pending) â€” ط¨ط¯ظˆظ† ط£طµظ†ط§ظپط› طھظڈط¶ط§ظپ ط¹ط¨ط± addItem
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
                'call_center_agent_id' => $data['call_center_agent_id'] ?? null,
                'order_type' => $data['order_type'],
                'source' => $data['source'] ?? 'pos',
                'status' => 'PENDING_PAYMENT',
                'table_number' => $data['table_number'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'customer_mobile' => $data['customer_mobile'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'customer_address_id' => $data['customer_address_id'] ?? null,
                'delivery_zone_id' => $data['order_type'] === 'delivery' ? ($data['delivery_zone_id'] ?? null) : null,
                // The server snapshots the fee during the first pricing calculation; never trust a client amount.
                'delivery_fee' => $data['order_type'] === 'delivery' && ! empty($data['delivery_zone_id']) ? null : 0,
                'delivery_address_snapshot' => $data['delivery_address_snapshot'] ?? null,
                'customer_notes' => $data['customer_notes'] ?? null,
                'delivery_notes' => $data['delivery_notes'] ?? null,
                'call_notes' => $data['call_notes'] ?? null,
                'needs_attention' => $data['needs_attention'] ?? false,
                'customer_service_flag' => $data['customer_service_flag'] ?? false,
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
            }
            app(\App\Services\Operations\OrderExecutionService::class)->recordEvent($order, 'order_created', null, $authUser, null, ['source'=>$order->source,'to_status'=>$order->status], $order->created_at);

            DB::commit();

            return $this->success(
                'طھظ… ط¥ظ†ط´ط§ط، ط§ظ„ط·ظ„ط¨',
                new OrderResource($order->load(['items.department', 'cashier'])),
                201
            );
        } catch (\InvalidArgumentException $e) {
            DB::rollBack();

            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->error('ظپط´ظ„ ط¥ظ†ط´ط§ط، ط§ظ„ط·ظ„ط¨: ' . $e->getMessage(), 500);
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

    /**
     * ط£ط¬ط²ط§ط، ط§ظ„ط·ظ„ط¨ ظ„ظ„ط·ط¨ط§ط¹ط© â€” ظ‚ط³ظ… ظˆط§ط­ط¯ ظ„ظƒظ„ ticket (ط¨ط¹ط¯ confirm)
     */
    public function printSections(Order $order): JsonResponse
    {
        return $this->success('ط£ط¬ط²ط§ط، ط§ظ„ط·ظ„ط¨ ظ„ظ„ط·ط¨ط§ط¹ط©', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'is_split' => $order->tickets()->exists(),
            'sections' => $order->sectionsForPrint(),
        ]);
    }

    public function update(UpdateOrderRequest $request, Order $order): JsonResponse
    {
        if (in_array($order->status, ['DELIVERED', 'CANCELLED'], true)) {
            return $this->error('ظ„ط§ ظٹظ…ظƒظ† طھط¹ط¯ظٹظ„ ط·ظ„ط¨ ظ…ط؛ظ„ظ‚ ط£ظˆ ظ…ظ„ط؛ظ‰.', 422);
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

            $data = $request->validated();
            $requestedType = $data['order_type'] ?? $order->order_type;
            $requestedZone = $data['delivery_zone_id'] ?? $order->delivery_zone_id;
            if ($requestedType !== 'delivery') {
                $data['delivery_zone_id'] = null;
                $data['delivery_fee'] = 0;
            } else {
                // Never accept a client-supplied fee. Requote only when the zone changes.
                $data['delivery_fee'] = (int) $requestedZone === (int) $order->delivery_zone_id
                    ? $order->delivery_fee
                    : null;
            }
            $order->update($data);
            $order->recalculateTotals();

            DB::commit();

            return $this->success(
                'طھظ… طھط­ط¯ظٹط« ط§ظ„ط·ظ„ط¨',
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
        if (in_array($order->status, ['DELIVERED', 'CANCELLED'], true)) {
            return $this->error('ظ„ط§ ظٹظ…ظƒظ† طھط¹ط¯ظٹظ„ طھط³ط¹ظٹط± ط·ظ„ط¨ ظ…ط؛ظ„ظ‚ ط£ظˆ ظ…ظ„ط؛ظ‰.', 422);
        }

        try {
            $data = $request->validated();
            $requestedType = $data['order_type'] ?? $order->order_type;
            $requestedZone = $data['delivery_zone_id'] ?? $order->delivery_zone_id;
            if ($requestedType !== 'delivery') {
                $data['delivery_zone_id'] = null;
                $data['delivery_fee'] = 0;
            } else {
                $data['delivery_fee'] = (int) $requestedZone === (int) $order->delivery_zone_id
                    ? $order->delivery_fee
                    : null;
            }
            $order->update($data);
            $order->recalculateTotals();

            return $this->success(
                'طھظ…طھ ظ…ط²ط§ظ…ظ†ط© ط§ظ„طھط³ط¹ظٹط±',
                new OrderResource($order->fresh()->load(['items.department', 'cashier']))
            );
        } catch (\Throwable $e) {
            return $this->error('ظپط´ظ„ ظ…ط²ط§ظ…ظ†ط© ط§ظ„طھط³ط¹ظٹط±: ' . $e->getMessage(), 500);
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
                'طھظ…طھ ط¥ط¶ط§ظپط© ط§ظ„طµظ†ظپ',
                [
                    'order' => new OrderResource($order->fresh()->load(['items.department', 'cashier'])),
                    'added_item' => new OrderItemResource($orderItem),
                ],
                201
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('ظپط´ظ„ ط¥ط¶ط§ظپط© ط§ظ„طµظ†ظپ: ' . $e->getMessage(), 500);
        }
    }

    public function removeItem(Order $order, OrderItem $orderItem): JsonResponse
    {
        if (in_array($order->status, ['paid', 'cancelled', 'served'])) {
            return $this->error('لا يمكن حذف أصناف من هذا الطلب.', 422);
        }

        if ($orderItem->order_id !== $order->id) {
            return $this->error('ط§ظ„طµظ†ظپ ظ„ط§ ظٹظ†طھظ…ظٹ ظ„ظ‡ط°ط§ ط§ظ„ط·ظ„ط¨.', 422);
        }

        if ($orderItem->status !== 'pending') {
            return $this->error('ظ„ط§ ظٹظ…ظƒظ† ط­ط°ظپ طµظ†ظپ ط£ظڈط±ط³ظ„ ظ„ظ„ظ…ط·ط¨ط®.', 422);
        }

        try {
            $orderItem->delete();
            $order->recalculateTotals();

            return $this->success(
                'طھظ… ط­ط°ظپ ط§ظ„طµظ†ظپ',
                new OrderResource($order->fresh()->load(['items.department', 'cashier']))
            );
        } catch (\Throwable $e) {
            return $this->error('ظپط´ظ„ ط­ط°ظپ ط§ظ„طµظ†ظپ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * ط¥ط±ط³ط§ظ„ ط§ظ„ط·ظ„ط¨ ظ„ظ„ظ…ط·ط¨ط® â€” طھط°ط§ظƒط± ط¥ظ†طھط§ط¬ ظ„ظƒظ„ ظ‚ط³ظ…
     */
    public function confirmPayment(Request $request, Order $order): JsonResponse
    {
        if ($order->status !== 'PENDING_PAYMENT') {
            return $this->error('Payment can only be confirmed for an order awaiting payment.', 422);
        }

        $validated = $request->validate([
            'transaction_id' => ['required', 'string', 'max:255'],
            'paid_at' => ['nullable', 'date'],
        ]);

        if (! $order->items()->exists()) {
            return $this->error('An empty order cannot be sent to preparation.', 422);
        }

        try {
            DB::transaction(function () use ($order, $validated) {
                $order->update([
                    'status' => 'PREPARATION',
                    'payment_status' => 'PAID',
                    'transaction_id' => trim($validated['transaction_id']),
                    'paid_at' => isset($validated['paid_at']) ? Carbon::parse($validated['paid_at']) : now(),
                ]);
                $this->syncProductionTickets($order);
            });

            return $this->success('Payment confirmed and order sent to preparation.',
                new OrderResource($order->fresh()->load(['items.department', 'tickets.ticketItems.orderItem', 'tickets.department'])));
        } catch (\Throwable $e) {
            return $this->error('Failed to confirm payment: '.$e->getMessage(), 500);
        }
    }

    /** @deprecated Use confirmPayment; retained for older clients. */
    public function confirm(Request $request, Order $order): JsonResponse
    {
        if ($request->filled('transaction_id')) {
            return $this->confirmPayment($request, $order);
        }

        if ($order->status !== 'PENDING_PAYMENT') {
            return $this->error('ط§ظ„ط·ظ„ط¨ ظ…ط¤ظƒط¯ ظ…ط³ط¨ظ‚ط§ظ‹.', 422);
        }

        if ($order->items()->count() === 0) {
            return $this->error('ظ„ط§ ظٹظ…ظƒظ† طھط£ظƒظٹط¯ ط·ظ„ط¨ ط¨ط¯ظˆظ† ط£طµظ†ط§ظپ.', 422);
        }

        DB::beginTransaction();
        try {
            // طھط¬ظ…ظٹط¹ ط§ظ„ط£طµظ†ط§ظپ ط­ط³ط¨ ط§ظ„ظ‚ط³ظ…
            $itemsByDept = $order->items()->with('department')->get()->groupBy('department_id');

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

            $order->update(['status' => 'PREPARATION']);

            // تحديث الطاولة إلى HAS_ORDER (عليها طلب)
            if ($order->dining_table_id) {
                $table = DiningTable::find($order->dining_table_id);
                if ($table) {
                    $table->update(['status' => 'HAS_ORDER']);
                }
            }

            DB::commit();

            return $this->success(
                'طھظ… ط¥ط±ط³ط§ظ„ ط§ظ„ط·ظ„ط¨ ظ„ظ„ط£ظ‚ط³ط§ظ…',
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

            return $this->error('ظپط´ظ„ طھط£ظƒظٹط¯ ط§ظ„ط·ظ„ط¨: ' . $e->getMessage(), 500);
        }
    }

    public function cancel(Order $order): JsonResponse
    {
        if (! in_array($order->status, ['pending', 'pending_confirmation', 'confirmed'], true)) {
            return $this->error('لا يمكن إلغاء هذا الطلب في حالته الحالية.', 422);
        }

        try {
            $reason = trim((string) $request->input('reason', ''));
            DB::transaction(function () use ($order, $reason) {
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
                app(\App\Services\Operations\OrderExecutionService::class)->recordEvent($order, 'cancelled', null, auth()->user(), $reason ?: null, ['to_status'=>Order::STATUS_CANCELLED], now());
            });

            return $this->success('طھظ… ط¥ظ„ط؛ط§ط، ط§ظ„ط·ظ„ط¨', new OrderResource($order->fresh()));
        } catch (\Throwable $e) {
            return $this->error('ظپط´ظ„ ط¥ظ„ط؛ط§ط، ط§ظ„ط·ظ„ط¨: ' . $e->getMessage(), 500);
        }
    }

    /**
     * طھط³ظ„ظٹظ… ط§ظ„ط·ظ„ط¨ â€” ready â†’ served + طھط³ظ„ظٹظ… ظƒظ„ ط§ظ„طھط°ط§ظƒط±
     */
    public function void(Request $request, Order $order): JsonResponse
    {
        if (in_array($order->status, ['DELIVERED', 'CANCELLED'], true)) {
            return $this->error('A delivered or cancelled order cannot be cancelled.', 422);
        }

        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            DB::transaction(function () use ($order, $validated) {
                $order->update([
                    'status' => 'CANCELLED',
                    'cancellation_reason' => trim($validated['reason']),
                    'cancelled_at' => now(),
                ]);
                $order->tickets()->update(['status' => 'cancelled']);
                $order->items()->update(['status' => 'cancelled']);
            });

            return $this->success('Order cancelled.', new OrderResource($order->fresh()));
        } catch (\Throwable $e) {
            return $this->error('Failed to cancel order: '.$e->getMessage(), 500);
        }
    }

    public function serve(Order $order): JsonResponse
    {
        if (! in_array($order->status, ['ready', 'in_progress'], true)) {
            return $this->error('ظٹط¬ط¨ ط£ظ† ظٹظƒظˆظ† ط§ظ„ط·ظ„ط¨ ط¬ط§ظ‡ط²ط§ظ‹ ظ‚ط¨ظ„ ط§ظ„طھط³ظ„ظٹظ….', 422);
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
                'طھظ… طھط³ظ„ظٹظ… ط§ظ„ط·ظ„ط¨',
                new OrderResource($order->fresh()->load(['items.department', 'tickets.department']))
            );
        } catch (\Throwable $e) {
            return $this->error('ظپط´ظ„ طھط³ظ„ظٹظ… ط§ظ„ط·ظ„ط¨: ' . $e->getMessage(), 500);
        }
    }

    /**
     * ط¹ط±ط¶ ط§ظ„ظ‚ظٹط¯ ط§ظ„ظ…ط­ط§ط³ط¨ظٹ ط§ظ„ظ…ط±طھط¨ط· ط¨ط·ظ„ط¨
     */

    public function markItemPrepared(Request $request, Order $order, OrderItem $orderItem): JsonResponse
    {
        if ($order->status !== 'PREPARATION') {
            return $this->error('Items can only be prepared after payment confirmation.', 422);
        }

        if ($orderItem->order_id !== $order->id) {
            return $this->error('الصنف لا ينتمي لهذا الطلب.', 422);
        }

        if ($order->status !== 'PREPARATION') {
            return $this->error('لا يمكن تحديث صنف لطلب مغلق أو ملغي.', 422);
        }

        $validated = $request->validate([
            'item_prepared_at' => ['nullable', 'date'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            DB::transaction(function () use ($order, $orderItem, $validated) {
                $preparedAt = isset($validated['item_prepared_at'])
                    ? Carbon::parse($validated['item_prepared_at'])
                    : now();

                $startedAt = $orderItem->sent_to_kitchen_at
                    ?? $order->paid_at
                    ?? $order->created_at;

                $duration = $validated['duration_seconds']
                    ?? max(0, $startedAt->diffInSeconds($preparedAt, false));

                $orderItem->update([
                    'status' => 'ready',
                    'item_prepared_at' => $preparedAt,
                    'prepared_duration_seconds' => $duration,
                ]);

                if ($ticketItem = $orderItem->ticketItem) {
                    $ticketItem->update(['status' => 'ready']);

                    $ticket = $ticketItem->ticket;
                    if ($ticket && ! $ticket->ticketItems()->whereNotIn('status', ['ready', 'served'])->exists()) {
                        $ticket->update([
                            'status' => 'ready',
                            'ready_at' => $ticket->ready_at ?? $preparedAt,
                        ]);
                    }
                }

            });

            return $this->success(
                'تم استلام الصنف وحفظ مدة التحضير',
                new OrderResource($order->fresh()->load(['items.department', 'tickets.ticketItems.orderItem', 'tickets.department', 'cashier']))
            );
        } catch (\Throwable $e) {
            return $this->error('فشل حفظ استلام الصنف: ' . $e->getMessage(), 500);
        }
    }

    public function markAssembled(Request $request, Order $order): JsonResponse
    {
        $validated=$request->validate(['assembler_id'=>['nullable','integer','exists:employees,id'],'assembled_at'=>['nullable','date']]);
        try {
            $assembler=isset($validated['assembler_id'])?\App\Models\Employee::find($validated['assembler_id']):($order->assembler_id?\App\Models\Employee::find($order->assembler_id):null);
            $updated=app(\App\Services\Operations\OrderExecutionService::class)->completeAssembly($order,$assembler,$request->user(),null,true);
            return $this->success('تم إنهاء تجميع الطلب وهو جاهز لاستدعاء الدليفري',new OrderResource($updated));
        } catch (\Throwable $e) {
            $status=$e instanceof \Illuminate\Validation\ValidationException?422:500;
            return $this->error($e->getMessage(),$status);
        }
    }

    public function markDelivered(Request $request, Order $order): JsonResponse
    {
        if (! in_array($order->status, ['OUT_FOR_DELIVERY', 'ready'], true)) {
            return $this->error('هذا الطلب مغلق أو ملغي مسبقا.', 422);
        }

        $validated = $request->validate([
            'delivered_at' => ['nullable', 'date'],
            'offline_recorded_at' => ['nullable', 'date'],
        ]);

        try {
            DB::transaction(function () use ($order, $validated) {
                $deliveredAt = isset($validated['delivered_at'])
                    ? Carbon::parse($validated['delivered_at'])
                    : now();
                $startedAt = $order->delivery_started_at ?? $order->assembled_at ?? $order->updated_at;

                $order->update([
                    'status' => 'DELIVERED',
                    'delivered_at' => $deliveredAt,
                    'delivery_duration_seconds' => max(0, $startedAt->diffInSeconds($deliveredAt, false)),
                ]);

                $order->tickets()->whereNotIn('status', ['cancelled'])->update([
                    'status' => 'served',
                    'served_at' => $deliveredAt,
                ]);
                $order->items()->whereNotIn('status', ['cancelled'])->update(['status' => 'served']);
                $driver = $order->driver_id ? \App\Models\Employee::find($order->driver_id) : null;
                app(\App\Services\Operations\OrderExecutionService::class)->recordEvent($order, 'delivered', $driver, auth()->user(), null, [
                    'to_status'=>Order::STATUS_DELIVERED,
                    'delivery_duration_seconds'=>max(0, $startedAt->diffInSeconds($deliveredAt, false)),
                    'driver_id'=>$order->driver_id,
                    'driver_name'=>$driver?->name ?? $order->delivery_employee_name,
                    'source'=>'operations_dashboard',
                ], $deliveredAt);
            });

            return $this->success(
                'تم تأكيد تسليم الطلب للزبون',
                new OrderResource($order->fresh()->load(['items.department', 'tickets.ticketItems.orderItem', 'tickets.department', 'cashier']))
            );
        } catch (\Throwable $e) {
            return $this->error('فشل تأكيد تسليم الطلب: ' . $e->getMessage(), 500);
        }
    }

    public function complete(Request $request, Order $order): JsonResponse
    {
        return $this->markDelivered($request, $order);
    }

    public function expedite(Request $request, Order $order): JsonResponse
    {
        if (! in_array($order->status, ['PREPARATION', 'confirmed', 'in_progress'], true)) {
            return $this->error('يمكن استعجال الطلبات قيد التحضير فقط.', 422);
        }

        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);
        $expeditedAt = now();

        DB::transaction(function () use ($order, $request, $validated, $expeditedAt) {
            $order->update([
                'is_urgent' => true,
                'priority' => 'urgent',
                'expedited_at' => $expeditedAt,
                'expedited_by' => $request->user()?->id,
            ]);

            app(\App\Services\Operations\OrderExecutionService::class)->recordEvent(
                $order,
                'expedited',
                $order->assembler_id ? \App\Models\Employee::find($order->assembler_id) : null,
                $request->user(),
                $validated['notes'] ?? null,
                ['priority' => 'urgent', 'source' => 'active_orders'],
                $expeditedAt
            );
        });

        return $this->success(
            'تم إرسال إشارة الاستعجال إلى مجمّع الطلبات والأقسام الإنتاجية.',
            new OrderResource($order->fresh()->load(['items.department', 'tickets.department', 'assembler', 'deliveryDriver']))
        );
    }

    public function storeCustomerExperience(Request $request, Order $order): JsonResponse
    {
        if (! in_array($order->status, ['DELIVERED', 'served', 'paid'], true)) {
            return $this->error('يمكن تقييم تجربة العميل بعد تسليم الطلب فقط.', 422);
        }

        $validated = $request->validate([
            'food_rating' => ['required', 'integer', 'between:1,5'],
            'delivery_rating' => ['required', 'integer', 'between:1,5'],
            'speed_rating' => ['required', 'integer', 'between:1,5'],
            'contacted' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $experience = OrderCustomerExperience::updateOrCreate(
            ['order_id' => $order->id],
            [
                ...$validated,
                'customer_id' => $order->customer_id,
                'recorded_by' => $request->user()?->id,
            ]
        );

        return $this->success('تم حفظ تقييم تجربة العميل.', $experience->fresh());
    }

    public function syncOfflineDeliveries(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'deliveries' => ['required', 'array'],
            'deliveries.*.order_id' => ['required', 'integer', 'exists:orders,id'],
            'deliveries.*.delivered_at' => ['required', 'date'],
            'deliveries.*.recorded_locally_at' => ['nullable', 'date'],
        ]);

        $orders = [];
        foreach ($validated['deliveries'] as $delivery) {
            $order = Order::findOrFail($delivery['order_id']);
            $this->markDelivered(new Request([
                'delivered_at' => $delivery['delivered_at'],
                'offline_recorded_at' => $delivery['recorded_locally_at'] ?? null,
            ]), $order);
            $orders[] = $order->fresh()->load(['items.department', 'tickets.department', 'cashier']);
        }

        return $this->success('تمت مزامنة تسليمات الدليفري', OrderResource::collection($orders));
    }

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
            return $this->error('ظ„ط§ ظٹظˆط¬ط¯ ظ‚ظٹط¯ ظ…ط­ط§ط³ط¨ظٹ ظ„ظ‡ط°ط§ ط§ظ„ط·ظ„ط¨ ط¨ط¹ط¯.', 404);
        }

        return $this->success('ط§ظ„ظ‚ظٹط¯ ط§ظ„ظ…ط­ط§ط³ط¨ظٹ', new TransactionResource($transaction));
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
            throw new \InvalidArgumentException('ط§ظ„طµظ†ظپ ط؛ظٹط± ظ…ظپط¹ظ‘ظ„ ط£ظˆ ط¨ط¯ظˆظ† ط³ط¹ط± ظپظٹ ظ‡ط°ط§ ط§ظ„ظپط±ط¹.');
        }

        if (! $item->department_id) {
            throw new \InvalidArgumentException('ط§ظ„طµظ†ظپ ط؛ظٹط± ظ…ط±ط¨ظˆط· ط¨ظ‚ط³ظ… â€” ظ„ط§ ظٹظ…ظƒظ† طھظ‚ط³ظٹظ…ظ‡ ظ„ظ„ظ…ط·ط¨ط®.');
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
