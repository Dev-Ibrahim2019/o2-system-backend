<?php
// app/Http/Controllers/Api/OrderController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductionTicket;
use App\Models\ProductionTicketItem;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends ApiController
{
    // ─────────────────────────────────────────────────────────────────────────
    // GET /orders
    // يدعم فلتر: branch_id, status, date
    // ─────────────────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $orders = Order::with(['items.department', 'tickets.department', 'cashier'])
            ->when($request->branch_id, fn($q) => $q->where('branch_id', $request->branch_id))
            ->when($request->status,    fn($q) => $q->where('status',    $request->status))
            ->when($request->date,      fn($q) => $q->whereDate('created_at', $request->date))
            ->orderByDesc('id')
            ->get();

        return $this->success('Orders fetched', OrderResource::collection($orders));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /orders
    // إنشاء طلب جديد بحالة "pending" (حفظ مؤقت)
    // ─────────────────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id'      => 'required|exists:branches,id',
            'cashier_id'     => 'nullable|exists:employees,id',
            'order_type'     => 'required|in:dine_in,takeaway',
            'table_number'   => 'nullable|string',
            'customer_name'  => 'nullable|string',
            'customer_phone' => 'nullable|string',
            'note'           => 'nullable|string',
            'discount_value' => 'nullable|numeric|min:0',
            'discount_type'  => 'nullable|in:amount,percent',
            'payment_method' => 'nullable|in:cash,credit_card,wallet',

            'items'              => 'required|array|min:1',
            'items.*.item_id'    => 'required|exists:items,id',
            'items.*.quantity'   => 'required|numeric|min:0.001',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.notes'      => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            // 1. احسب المجاميع
            $subtotal = collect($data['items'])->sum(fn($i) => $i['unit_price'] * $i['quantity']);

            $discountType  = $data['discount_type']  ?? 'amount';
            $discountValue = $data['discount_value'] ?? 0;
            $discountAmount = $discountType === 'percent'
                ? ($subtotal * $discountValue / 100)
                : $discountValue;

            $total = max(0, $subtotal - $discountAmount);

            // 2. أنشئ الطلب
            $order = Order::create([
                'order_number'   => Order::generateOrderNumber(),
                'branch_id'      => $data['branch_id'],
                'cashier_id'     => $data['cashier_id'] ?? null,
                'order_type'     => $data['order_type'],
                'status'         => 'pending',
                'table_number'   => $data['table_number'] ?? null,
                'customer_name'  => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'note'           => $data['note'] ?? null,
                'subtotal'       => $subtotal,
                'discount_value' => $discountValue,
                'discount_type'  => $discountType,
                'discount_amount' => $discountAmount,
                'total'          => $total,
                'payment_method' => $data['payment_method'] ?? null,
            ]);

            // 3. أنشئ أصناف الطلب (مع جلب معلومات القسم)
            foreach ($data['items'] as $itemData) {
                $item = Item::with('department')->findOrFail($itemData['item_id']);

                OrderItem::create([
                    'order_id'      => $order->id,
                    'item_id'       => $item->id,
                    'department_id' => $item->department_id,
                    'item_name'     => $item->name,
                    'item_name_ar'  => $item->name_ar ?? $item->name,
                    'unit_price'    => $itemData['unit_price'],
                    'quantity'      => $itemData['quantity'],
                    'total_price'   => $itemData['unit_price'] * $itemData['quantity'],
                    'notes'         => $itemData['notes'] ?? null,
                ]);
            }

            DB::commit();

            return $this->success(
                'Order created',
                new OrderResource($order->load(['items.department', 'tickets', 'cashier'])),
                201
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('Failed to create order: ' . $e->getMessage(), 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /orders/{order}
    // ─────────────────────────────────────────────────────────────────────────
    public function show(Order $order)
    {
        return $this->success(
            'Order fetched',
            new OrderResource($order->load(['items.department', 'tickets.ticketItems', 'tickets.department', 'cashier']))
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /orders/{order}
    // تعديل طلب (فقط إذا كان pending)
    // ─────────────────────────────────────────────────────────────────────────
    public function update(Request $request, Order $order)
    {
        if (!in_array($order->status, ['pending'])) {
            return $this->error('Cannot edit an order that is already confirmed or later.', 422);
        }

        $data = $request->validate([
            'order_type'     => 'sometimes|in:dine_in,takeaway',
            'table_number'   => 'nullable|string',
            'customer_name'  => 'nullable|string',
            'customer_phone' => 'nullable|string',
            'note'           => 'nullable|string',
            'discount_value' => 'nullable|numeric|min:0',
            'discount_type'  => 'nullable|in:amount,percent',
            'payment_method' => 'nullable|in:cash,credit_card,wallet',

            'items'              => 'sometimes|array|min:1',
            'items.*.item_id'    => 'required_with:items|exists:items,id',
            'items.*.quantity'   => 'required_with:items|numeric|min:0.001',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
            'items.*.notes'      => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            // إعادة حساب الأصناف إذا أُرسلت
            if (isset($data['items'])) {
                $order->items()->delete();

                foreach ($data['items'] as $itemData) {
                    $item = Item::with('department')->findOrFail($itemData['item_id']);
                    OrderItem::create([
                        'order_id'      => $order->id,
                        'item_id'       => $item->id,
                        'department_id' => $item->department_id,
                        'item_name'     => $item->name,
                        'item_name_ar'  => $item->name_ar ?? $item->name,
                        'unit_price'    => $itemData['unit_price'],
                        'quantity'      => $itemData['quantity'],
                        'total_price'   => $itemData['unit_price'] * $itemData['quantity'],
                        'notes'         => $itemData['notes'] ?? null,
                    ]);
                }

                // إعادة حساب المجاميع
                $subtotal       = $order->fresh()->items->sum('total_price');
                $discountType   = $data['discount_type']  ?? $order->discount_type;
                $discountValue  = $data['discount_value'] ?? $order->discount_value;
                $discountAmount = $discountType === 'percent'
                    ? ($subtotal * $discountValue / 100)
                    : $discountValue;

                $data['subtotal']        = $subtotal;
                $data['discount_amount'] = $discountAmount;
                $data['total']           = max(0, $subtotal - $discountAmount);

                unset($data['items']);
            }

            $order->update($data);

            DB::commit();

            return $this->success(
                'Order updated',
                new OrderResource($order->fresh()->load(['items.department', 'tickets', 'cashier']))
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('Failed to update order: ' . $e->getMessage(), 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /orders/{order}/confirm
    // تأكيد الطلب → إنشاء تذاكر الأقسام الإنتاجية
    // ─────────────────────────────────────────────────────────────────────────
    public function confirm(Order $order)
    {
        if ($order->status !== 'pending') {
            return $this->error('Order is already confirmed.', 422);
        }

        DB::beginTransaction();

        try {
            // 1. جمّع الأصناف حسب القسم
            $itemsByDept = $order->items->groupBy('department_id');

            foreach ($itemsByDept as $deptId => $deptItems) {
                // 2. أنشئ تذكرة لكل قسم
                $ticket = ProductionTicket::create([
                    'order_id'      => $order->id,
                    'department_id' => $deptId,
                    'ticket_number' => ProductionTicket::generateTicketNumber($deptId),
                    'status'        => 'pending',
                    'notes'         => $order->note,
                ]);

                // 3. أضف أصناف التذكرة
                foreach ($deptItems as $orderItem) {
                    ProductionTicketItem::create([
                        'ticket_id'    => $ticket->id,
                        'order_item_id' => $orderItem->id,
                        'item_name'    => $orderItem->item_name,
                        'item_name_ar' => $orderItem->item_name_ar,
                        'quantity'     => $orderItem->quantity,
                        'notes'        => $orderItem->notes,
                        'status'       => 'pending',
                    ]);
                }
            }

            // 4. حدّث حالة الطلب
            $order->update(['status' => 'confirmed']);

            DB::commit();

            return $this->success(
                'Order confirmed and tickets created',
                new OrderResource($order->fresh()->load(['items.department', 'tickets.ticketItems', 'tickets.department']))
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('Failed to confirm order: ' . $e->getMessage(), 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /orders/{order}/cancel
    // ─────────────────────────────────────────────────────────────────────────
    public function cancel(Order $order)
    {
        if (in_array($order->status, ['paid', 'served'])) {
            return $this->error('Cannot cancel a completed order.', 422);
        }

        $order->update(['status' => 'cancelled']);
        $order->tickets()->update(['status' => 'cancelled']);

        return $this->success('Order cancelled', new OrderResource($order->fresh()));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /orders/{order}  (soft delete)
    // ─────────────────────────────────────────────────────────────────────────
    public function destroy(Order $order)
    {
        $order->delete();
        return $this->success('Order deleted', []);
    }
}
