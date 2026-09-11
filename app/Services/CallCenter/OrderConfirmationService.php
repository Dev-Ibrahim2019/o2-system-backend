<?php

namespace App\Services\CallCenter;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\ProductionTicket;
use App\Models\ProductionTicketItem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * تنفيذ الطلب بالأقسام (إنشاء تذاكر الأقسام من العناصر غير المُرسلة بعد) — مُستخرج حرفيًا من
 * OrderController::confirm() عشان يُستدعى من الكنترولر (زر "-" وضغطة الموظف اليدوية) وأمر
 * الجدولة التلقائية (orders:execute-scheduled) معًا، بدل ما يكون عندنا نسختين مختلفتين لنفس
 * العملية. يرمي InvalidArgumentException لأخطاء العمل (كانت 422 بالكنترولر الأصلي)، ويترك أي
 * \Throwable آخر (فشل أثناء المعاملة) يتصعّد للمستدعي كما هو.
 */
class OrderConfirmationService
{
    public function confirmOrder(Order $order): Order
    {
        if ($order->source === 'call_center' && $order->status !== 'paid') {
            throw new InvalidArgumentException('لا يمكن إرسال طلب الكول سنتر للمطبخ قبل اكتمال الفاتورة والدفع.');
        }

        if (! in_array($order->status, ['pending', 'pending_confirmation', 'paid'], true)) {
            throw new InvalidArgumentException('لا يمكن تأكيد هذا الطلب في حالته الحالية.');
        }

        $unsentItems = $order->items()
            ->with('department')
            ->where('status', 'pending')
            ->get();

        if ($unsentItems->isEmpty()) {
            throw new InvalidArgumentException('لا توجد عناصر جديدة لإرسالها.');
        }

        DB::transaction(function () use ($order, $unsentItems) {
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

            // A call-center order may be financially closed before kitchen
            // submission. Preserve its paid state while creating production
            // tickets instead of reopening the financial lifecycle.
            if ($order->status !== 'paid') {
                $order->update(['status' => 'confirmed']);
            }

            // تحديث الطاولة إلى HAS_ORDER (عليها طلب)
            if ($order->dining_table_id) {
                $table = DiningTable::find($order->dining_table_id);
                if ($table) {
                    $table->update(['status' => 'HAS_ORDER']);
                }
            }
        });

        return $order->fresh()->load([
            'items.department',
            'tickets.ticketItems.orderItem',
            'tickets.department',
        ]);
    }
}
