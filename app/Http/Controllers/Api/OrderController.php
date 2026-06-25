<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\AddOrderItemRequest;
use App\Http\Requests\Api\StoreOrderRequest;
use App\Http\Requests\Api\UpdateOrderRequest;
use App\Http\Resources\AccountingResources\TransactionResource;
use App\Http\Resources\OrderItemResource;
use App\Http\Resources\OrderResource;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductionTicket;
use App\Models\ProductionTicketItem;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

            // حماية: حقن branch_id تلقائياً من المستخدم المسجل
            // super-admin (branch_id = null) يمكنه تمرير branch_id يدوياً
            // إذا كان كل شيء null، نأخذ أول برانش موجود كـ fallback
            $branchId = $authUser->branch_id ?? $data['branch_id'] ?? \App\Models\Branch::value('id');

            $order = Order::create([
                'order_number' => Order::generateOrderNumber(),
                'branch_id' => $branchId,
                'cashier_id' => $data['cashier_id'] ?? null,
                'order_type' => $data['order_type'],
                'status' => 'pending',
                'table_number' => $data['table_number'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'note' => $data['note'] ?? null,
                'subtotal' => 0,
                'discount_value' => $data['discount_value'] ?? 0,
                'discount_type' => $data['discount_type'] ?? 'amount',
                'discount_amount' => 0,
                'total' => 0,
            ]);

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
                $this->syncProductionTickets($order);
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

    /**
     * أجزاء الطلب للطباعة — قسم واحد لكل ticket (بعد confirm)
     */
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
        if ($order->status !== 'pending') {
            return $this->error('لا يمكن تعديل طلب بعد تأكيده.', 422);
        }

        try {
            $order->update($request->validated());
            $order->recalculateTotals();

            return $this->success(
                'تم تحديث الطلب',
                new OrderResource($order->fresh()->load(['items.department', 'cashier']))
            );
        } catch (\Throwable $e) {
            return $this->error('فشل تحديث الطلب: ' . $e->getMessage(), 500);
        }
    }

    /**
     * إضافة صنف — جلب السعر من branch_item وحفظ الاسم والسعر وقت الإضافة
     */
    public function addItem(AddOrderItemRequest $request, Order $order): JsonResponse
    {
        if ($order->status !== 'pending') {
            return $this->error('لا يمكن إضافة أصناف لطلب مؤكد.', 422);
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
            $this->syncProductionTickets($order);

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
     * حذف صنف — فقط إذا كان pending
     */
    public function removeItem(Order $order, OrderItem $orderItem): JsonResponse
    {
        if ($order->status !== 'pending') {
            return $this->error('لا يمكن حذف أصناف من طلب مؤكد.', 422);
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
     * إرسال الطلب للمطبخ — تذاكر إنتاج لكل قسم
     */
    public function confirm(Order $order): JsonResponse
    {
        if ($order->status !== 'pending') {
            return $this->error('الطلب مؤكد مسبقاً.', 422);
        }

        if ($order->items()->count() === 0) {
            return $this->error('لا يمكن تأكيد طلب بدون أصناف.', 422);
        }

        DB::beginTransaction();
        try {
            // تجميع الأصناف حسب القسم
            $itemsByDept = $order->items()->with('department')->get()->groupBy('department_id');

            foreach ($itemsByDept as $deptId => $deptItems) {
                if (! $deptId) {
                    continue;
                }

                $ticket = ProductionTicket::create([
                    'order_id' => $order->id,
                    'department_id' => $deptId,
                    'ticket_number' => ProductionTicket::generateTicketNumber((int) $deptId),
                    'status' => 'pending',
                    'sent_at' => now(),
                    'notes' => $order->note,
                ]);

                foreach ($deptItems as $orderItem) {
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

            return $this->error('فشل تأكيد الطلب: ' . $e->getMessage(), 500);
        }
    }

    /**
     * إلغاء الطلب — pending أو confirmed فقط
     */
    public function cancel(Order $order): JsonResponse
    {
        if (! in_array($order->status, ['pending', 'confirmed'], true)) {
            return $this->error('لا يمكن إلغاء هذا الطلب في حالته الحالية.', 422);
        }

        try {
            DB::transaction(function () use ($order) {
                $order->update(['status' => 'cancelled']);
                $order->tickets()->update(['status' => 'cancelled']);
                $order->items()->update(['status' => 'cancelled']);
            });

            return $this->success('تم إلغاء الطلب', new OrderResource($order->fresh()));
        } catch (\Throwable $e) {
            return $this->error('فشل إلغاء الطلب: ' . $e->getMessage(), 500);
        }
    }

    /**
     * تسليم الطلب — ready → served + تسليم كل التذاكر
     */
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
     * عرض القيد المحاسبي المرتبط بطلب
     */
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

    private function syncProductionTickets(Order $order): void
    {
        if (in_array($order->status, ['cancelled', 'paid'], true)) {
            return;
        }

        $itemsByDept = $order->items()
            ->whereDoesntHave('ticketItem')
            ->get()
            ->groupBy('department_id');

        if ($itemsByDept->isEmpty()) {
            return;
        }

        foreach ($itemsByDept as $deptId => $orderItems) {
            if (! $deptId) {
                continue;
            }

            $ticket = ProductionTicket::create([
                'order_id' => $order->id,
                'department_id' => $deptId,
                'ticket_number' => ProductionTicket::generateTicketNumber((int) $deptId),
                'status' => 'pending',
                'sent_at' => now(),
                'notes' => $order->note,
            ]);

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

        if (! in_array($order->status, ['confirmed', 'in_progress', 'ready', 'served'], true)) {
            $order->update(['status' => 'confirmed']);
        }
    }

    /**
     * إنشاء بند طلب واحد مع السعر من branch_item (أو unit_price المرسل)
     *
     * @throws \InvalidArgumentException
     */
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
            'quantity' => $quantity,
            'total' => round($price * $quantity, 2),
            'status' => 'pending',
            'notes' => $notes,
        ]);
    }
}
