<?php
// app/Http/Controllers/Api/OrderController.php — النسخة الكاملة المصححة

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
    // POST /orders — إنشاء طلب pending
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
            $subtotal       = collect($data['items'])->sum(fn($i) => $i['unit_price'] * $i['quantity']);
            $discountType   = $data['discount_type']  ?? 'amount';
            $discountValue  = $data['discount_value'] ?? 0;
            $discountAmount = $discountType === 'percent'
                ? ($subtotal * $discountValue / 100)
                : $discountValue;
            $total = max(0, $subtotal - $discountAmount);

            $order = Order::create([
                'order_number'    => Order::generateOrderNumber(),
                'branch_id'       => $data['branch_id'],
                'cashier_id'      => $data['cashier_id'] ?? null,
                'order_type'      => $data['order_type'],
                'status'          => 'pending',
                'table_number'    => $data['table_number'] ?? null,
                'customer_name'   => $data['customer_name'] ?? null,
                'customer_phone'  => $data['customer_phone'] ?? null,
                'note'            => $data['note'] ?? null,
                'subtotal'        => $subtotal,
                'discount_value'  => $discountValue,
                'discount_type'   => $discountType,
                'discount_amount' => $discountAmount,
                'total'           => $total,
                'payment_method'  => $data['payment_method'] ?? null,
            ]);

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
            new OrderResource($order->load([
                'items.department',
                'tickets.ticketItems',
                'tickets.department',
                'cashier',
            ]))
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /orders/{order} — تعديل (pending فقط)
    // ─────────────────────────────────────────────────────────────────────────
    public function update(Request $request, Order $order)
    {
        if ($order->status !== 'pending') {
            return $this->error('لا يمكن تعديل طلب بعد تأكيده.', 422);
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
    // POST /orders/{order}/confirm — إرسال للمطبخ/البار
    // ─────────────────────────────────────────────────────────────────────────
    public function confirm(Order $order)
    {
        if ($order->status !== 'pending') {
            return $this->error('الطلب مؤكد مسبقاً.', 422);
        }

        DB::beginTransaction();
        try {
            $itemsByDept = $order->items->groupBy('department_id');

            foreach ($itemsByDept as $deptId => $deptItems) {
                $ticket = ProductionTicket::create([
                    'order_id'      => $order->id,
                    'department_id' => $deptId,
                    'ticket_number' => ProductionTicket::generateTicketNumber($deptId),
                    'status'        => 'pending',
                    'notes'         => $order->note,
                ]);

                foreach ($deptItems as $orderItem) {
                    ProductionTicketItem::create([
                        'ticket_id'     => $ticket->id,
                        'order_item_id' => $orderItem->id,
                        'item_name'     => $orderItem->item_name,
                        'item_name_ar'  => $orderItem->item_name_ar,
                        'quantity'      => $orderItem->quantity,
                        'notes'         => $orderItem->notes,
                        'status'        => 'pending',
                    ]);
                }
            }

            $order->update(['status' => 'confirmed']);
            DB::commit();

            return $this->success(
                'تم إرسال الطلب للأقسام بنجاح',
                new OrderResource($order->fresh()->load([
                    'items.department',
                    'tickets.ticketItems',
                    'tickets.department',
                ]))
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('Failed to confirm order: ' . $e->getMessage(), 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /orders/{order}/pay
    // إغلاق الطلب ماليًا مع دعم الرقم المرجعي للمحفظة/التطبيق
    //
    // body: {
    //   payment_method: 'cash' | 'credit_card' | 'wallet',
    //   reference_number?: string   // مطلوب فقط إذا wallet
    //   customer_name?: string
    //   customer_phone?: string
    // }
    // ─────────────────────────────────────────────────────────────────────────
    public function pay(Request $request, Order $order)
    {
        if (in_array($order->status, ['paid', 'cancelled'])) {
            return $this->error('لا يمكن إغلاق هذا الطلب.', 422);
        }

        $data = $request->validate([
            'payment_method'   => 'required|in:cash,credit_card,wallet',
            'reference_number' => [
                'nullable',
                'string',
                'max:100',
                // التحقق من عدم تكرار الرقم المرجعي في طلبات أخرى
                'unique:orders,reference_number,' . $order->id,
            ],
            'customer_name'    => 'nullable|string',
            'customer_phone'   => 'nullable|string',
        ]);

        // إذا الدفع بالمحفظة/التطبيق → الرقم المرجعي مطلوب
        if ($data['payment_method'] === 'wallet' && empty($data['reference_number'])) {
            return $this->error('الرقم المرجعي مطلوب عند الدفع بالمحفظة أو التطبيق.', 422);
        }

        // التحقق المضاعف: هل الرقم المرجعي مستخدم في طلب آخر مدفوع؟
        if (!empty($data['reference_number'])) {
            $existing = Order::where('reference_number', $data['reference_number'])
                ->where('id', '!=', $order->id)
                ->where('status', 'paid')
                ->first();

            if ($existing) {
                return $this->error(
                    "الرقم المرجعي مستخدم مسبقاً في الطلب #{$existing->order_number}.",
                    409
                );
            }
        }

        $updateData = [
            'payment_method'   => $data['payment_method'],
            'reference_number' => $data['reference_number'] ?? null,
            'status'           => 'paid',
            'paid_at'          => now(),
        ];

        if (!empty($data['customer_name'])) {
            $updateData['customer_name'] = $data['customer_name'];
        }
        if (!empty($data['customer_phone'])) {
            $updateData['customer_phone'] = $data['customer_phone'];
        }

        $order->update($updateData);

        return $this->success(
            'تم إغلاق الطلب وتسجيل الدفع بنجاح',
            new OrderResource($order->fresh()->load(['items.department', 'tickets', 'cashier']))
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /payments/verify-reference
    // التحقق من الرقم المرجعي قبل الإرسال
    //
    // body: { reference_number: string, order_id?: number }
    // response: { valid: bool, message: string, existing_order?: {...} }
    // ─────────────────────────────────────────────────────────────────────────
    public function verifyReference(Request $request)
    {
        $data = $request->validate([
            'reference_number' => 'required|string|max:100',
            'order_id'         => 'nullable|integer',
        ]);

        $query = Order::where('reference_number', $data['reference_number']);

        // تجاهل الطلب الحالي إذا مُرِّر
        if (!empty($data['order_id'])) {
            $query->where('id', '!=', $data['order_id']);
        }

        $existing = $query->first();

        if ($existing) {
            return $this->success('تحقق من الرقم المرجعي', [
                'valid'   => false,
                'message' => "هذا الرقم المرجعي مستخدم مسبقاً في الطلب #{$existing->order_number}",
                'existing_order' => [
                    'id'           => $existing->id,
                    'order_number' => $existing->order_number,
                    'status'       => $existing->status,
                    'total'        => (float) $existing->total,
                    'paid_at'      => $existing->paid_at?->toIso8601String(),
                ],
            ]);
        }

        return $this->success('تحقق من الرقم المرجعي', [
            'valid'   => true,
            'message' => 'الرقم المرجعي متاح للاستخدام',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /orders/{order}/cancel
    // ─────────────────────────────────────────────────────────────────────────
    public function cancel(Order $order)
    {
        if (in_array($order->status, ['paid', 'served'])) {
            return $this->error('لا يمكن إلغاء طلب مكتمل.', 422);
        }

        $order->update(['status' => 'cancelled']);
        $order->tickets()->update(['status' => 'cancelled']);

        return $this->success('تم إلغاء الطلب', new OrderResource($order->fresh()));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /orders/{order}/void — إلغاء مع سبب (للكاشير)
    // ─────────────────────────────────────────────────────────────────────────
    public function void(Request $request, Order $order)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        if (in_array($order->status, ['paid'])) {
            return $this->error('لا يمكن void طلب مدفوع.', 422);
        }

        $order->update([
            'status' => 'cancelled',
            'note'   => ($order->note ? $order->note . ' | ' : '') . 'إلغاء: ' . $data['reason'],
        ]);
        $order->tickets()->update(['status' => 'cancelled']);

        return $this->success('تم إلغاء الطلب', new OrderResource($order->fresh()));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /orders/{order}
    // ─────────────────────────────────────────────────────────────────────────
    public function destroy(Order $order)
    {
        $order->delete();
        return $this->success('Order deleted', []);
    }
}
