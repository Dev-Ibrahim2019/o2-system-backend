<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductionTicket;
use App\Models\ProductionTicketItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerOrderController extends ApiController
{
    /**
     * الزبون يرسل طلبه (أو يضيف أصناف على طلب موجود)
     * POST /api/customer/orders
     *
     * السيناريوهات:
     * - لا يوجد طلب نشط → إنشاء طلب جديد بحالة pending_confirmation
     * - طلب pending_confirmation → إضافة الأصناف عليه ويبقى pending_confirmation
     * - طلب confirmed/in_progress → إضافة العناصر الجديدة بحالة pending (تحتاج ترحيل)
     *
     * عام بدون تسجيل دخول
     */
    public function store(Request $request)
    {
        $request->validate([
            'qr_code'   => 'required|string',
            'items'     => 'required|array|min:1',
            'items.*.item_id'   => 'required|integer|exists:items,id',
            'items.*.quantity'  => 'required|integer|min:1',
            'items.*.note'      => 'nullable|string|max:255',
        ]);

        $table = DiningTable::where('qr_code', $request->qr_code)
            ->with('zone.branch')
            ->first();

        if (!$table) {
            return response()->json([
                'success' => false,
                'message' => 'الطاولة غير موجودة',
            ], 404);
        }

        $branch = $table->zone?->branch;
        if (!$branch) {
            return response()->json([
                'success' => false,
                'message' => 'الفرع غير مرتبط بالطاولة',
            ], 400);
        }

        DB::beginTransaction();
        try {
            // هل يوجد طلب نشط يمكن الإضافة عليه؟
            $existingOrder = null;
            if ($table->current_order_id) {
                $existingOrder = Order::where('id', $table->current_order_id)
                    ->whereIn('status', ['pending_confirmation', 'pending', 'confirmed', 'in_progress'])
                    ->first();
            }

            if ($existingOrder) {
                $order = $existingOrder;

                foreach ($request->items as $row) {
                    $item = \App\Models\Item::find($row['item_id']);
                    if (!$item) continue;

                    $price = DB::table('branch_item')
                        ->where('branch_id', $branch->id)
                        ->where('item_id', $item->id)
                        ->value('price') ?? 0;

                    // هل الصنف موجود مسبقاً في الطلب وحالة pending (لم يُرسل بعد)؟
                    $existingItem = $order->items()
                        ->where('item_id', $item->id)
                        ->where('status', 'pending')
                        ->first();

                    if ($existingItem) {
                        $existingItem->increment('quantity', $row['quantity']);
                        $existingItem->update([
                            'total' => $existingItem->price * $existingItem->quantity,
                        ]);
                    } else {
                        // إضافة صنف جديد بحالة pending (لم يُرسل بعد)
                        OrderItem::create([
                            'order_id'      => $order->id,
                            'item_id'       => $item->id,
                            'item_name'     => $item->name,
                            'item_name_ar'  => $item->name_ar ?? $item->name,
                            'quantity'      => $row['quantity'],
                            'price'         => $price,
                            'total'         => $price * $row['quantity'],
                            'notes'         => $row['note'] ?? null,
                            'department_id' => $item->department_id,
                            'status'        => 'pending',
                        ]);
                    }
                }

                // إذا الطلب كان pending_confirmation → يبقى pending_confirmation
                // إذا الطلب كان confirmed/in_progress → نعيده لـ pending_confirmation
                // لأن فيه عناصر جديدة يحتاج النادل يراجعها ويرحّلها
                $order->update(['status' => 'pending_confirmation']);

                $order->recalculateTotals();

                // تحديث حالة الطاولة دائماً إلى PENDING_CONFIRMATION
                // لأن الزبون أضاف عناصر جديدة تحتاج مراجعة النادل
                $table->update([
                    'status'           => 'PENDING_CONFIRMATION',
                    'current_order_id' => $order->id,
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'تم إضافة الأصناف وتحديث حالة الطاولة',
                    'data' => [
                        'order_id'     => $order->id,
                        'order_number' => $order->order_number,
                        'status'       => $order->status,
                        'table_number' => $table->table_number,
                        'is_update'    => true,
                        'has_unsent_items' => $order->hasUnsentItems(),
                    ],
                ], 200);
            }

            // ── إنشاء طلب جديد ──
            $order = Order::create([
                'order_number'   => Order::generateOrderNumber(),
                'dining_table_id' => $table->id,
                'branch_id'      => $branch->id,
                'order_type'     => 'dine_in',
                'status'         => 'pending_confirmation',
                'table_number'   => $table->table_number,
                'note'           => 'طلب من الزبون عبر QR',
                'subtotal'       => 0,
                'discount_value' => 0,
                'discount_type'  => 'amount',
                'discount_amount'=> 0,
                'engine_discount_amount' => 0,
                'total'          => 0,
            ]);

            foreach ($request->items as $row) {
                $item = \App\Models\Item::find($row['item_id']);
                if (!$item) continue;

                $price = DB::table('branch_item')
                    ->where('branch_id', $branch->id)
                    ->where('item_id', $item->id)
                    ->value('price') ?? 0;

                OrderItem::create([
                    'order_id'      => $order->id,
                    'item_id'       => $item->id,
                    'item_name'     => $item->name,
                    'item_name_ar'  => $item->name_ar ?? $item->name,
                    'quantity'      => $row['quantity'],
                    'price'         => $price,
                    'total'         => $price * $row['quantity'],
                    'notes'         => $row['note'] ?? null,
                    'department_id' => $item->department_id,
                    'status'        => 'pending',
                ]);
            }

            $order->recalculateTotals();

            $table->update([
                'status'           => 'PENDING_CONFIRMATION',
                'current_order_id' => $order->id,
                'seated_at'        => $table->seated_at ?? now(),
                'customer_count'   => max($table->customer_count, 1),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'تم إرسال الطلب بنجاح',
                'data' => [
                    'order_id'     => $order->id,
                    'order_number' => $order->order_number,
                    'status'       => $order->status,
                    'table_number' => $table->table_number,
                    'is_update'    => false,
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'فشل إرسال الطلب: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * جلب الطلب النشط للطاولة
     * GET /api/customer/table/{qrCode}/active-order
     */
    public function activeOrder(Request $request, string $qrCode)
    {
        $table = DiningTable::where('qr_code', $qrCode)
            ->with('zone.branch')
            ->first();

        if (!$table) {
            return response()->json([
                'success' => false,
                'message' => 'الطاولة غير موجودة',
            ], 404);
        }

        if (!$table->current_order_id) {
            return response()->json([
                'success' => false,
                'message' => 'لا يوجد طلب نشط لهذه الطاولة',
                'data'    => null,
            ], 200);
        }

        $order = Order::with('items')
            ->where('id', $table->current_order_id)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'الطلب غير موجود',
                'data'    => null,
            ], 200);
        }

        $items = $order->items->map(function ($orderItem) {
            return [
                'item_id'     => $orderItem->item_id,
                'item_name'   => $orderItem->item_name,
                'item_name_ar'=> $orderItem->item_name_ar,
                'quantity'    => (float) $orderItem->quantity,
                'price'       => (float) $orderItem->price,
                'total'       => (float) $orderItem->total,
                'notes'       => $orderItem->notes,
                'status'      => $orderItem->status,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'order_id'      => $order->id,
                'order_number'  => $order->order_number,
                'status'        => $order->status,
                'subtotal'      => (float) $order->subtotal,
                'discount_value'=> (float) $order->discount_value,
                'discount_amount'=> (float) $order->discount_amount,
                'total'         => (float) $order->total,
                'items'         => $items,
                'has_unsent_items' => $order->hasUnsentItems(),
                'created_at'    => $order->created_at,
            ],
        ]);
    }

    /**
     * تأكيد الطلب من القرصون/الكاشير
     * POST /api/orders/{order}/confirm-customer
     *
     * يرسل فقط العناصر بحالة pending (الجديدة) — لا يعيد إرسال القديمة.
     */
    public function confirmOrder(Order $order)
    {
        if (!in_array($order->status, ['pending_confirmation', 'pending'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'الطلب ليس في حالة تسمح بالتأكيد',
            ], 400);
        }

        // فلترة العناصر غير المرحّلة فقط
        $unsentItems = $order->items()->where('status', 'pending')->get();

        if ($unsentItems->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'لا توجد عناصر جديدة لإرسالها',
            ], 400);
        }

        DB::beginTransaction();
        try {
            $itemsByDept = $unsentItems->with('department')->groupBy('department_id');

            foreach ($itemsByDept as $deptId => $deptItems) {
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

                foreach ($deptItems as $orderItem) {
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

            // تحديث حالة الطاولة إلى OCCUPIED
            if ($order->dining_table_id) {
                $table = DiningTable::find($order->dining_table_id);
                if ($table) {
                    $table->update(['status' => 'OCCUPIED']);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'تم تأكيد الطلب وإرساله للأقسام',
                'data' => [
                    'order_id'   => $order->id,
                    'status'     => $order->status,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'فشل تأكيد الطلب: ' . $e->getMessage(),
            ], 500);
        }
    }
}
