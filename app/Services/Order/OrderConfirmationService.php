<?php

namespace App\Services\Order;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\ProductionTicket;
use App\Models\ProductionTicketItem;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class OrderConfirmationService
{
    public function release(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($lockedOrder->source === 'call_center' && $lockedOrder->payment_status !== Order::PAYMENT_STATUS_PAID) {
                throw new UnprocessableEntityHttpException('لا يمكن إرسال طلب الكول سنتر للمطبخ قبل اكتمال الفاتورة والدفع.');
            }
            if (in_array($lockedOrder->status, ['cancelled', 'paid', 'served'], true)) {
                throw new UnprocessableEntityHttpException('لا يمكن تأكيد هذا الطلب في حالته الحالية.');
            }

            $unsentItems = $lockedOrder->items()
                ->where('status', 'pending')
                ->whereDoesntHave('ticketItem')
                ->lockForUpdate()
                ->get();

            if ($unsentItems->isEmpty()) {
                if ($lockedOrder->source === 'call_center' && $lockedOrder->tickets()->exists()) return $lockedOrder->fresh();
                throw new UnprocessableEntityHttpException('لا توجد عناصر جديدة لإرسالها.');
            }

            if ($unsentItems->contains(fn ($item) => ! $item->department_id)) {
                throw new UnprocessableEntityHttpException('لا يمكن إرسال الطلب: يوجد صنف غير مرتبط بقسم إنتاج.');
            }

            foreach ($unsentItems->groupBy('department_id') as $departmentId => $items) {
                $ticket = $lockedOrder->tickets()->where('department_id', $departmentId)
                    ->whereIn('status', ['pending', 'preparing'])->lockForUpdate()->first();
                $ticket ??= ProductionTicket::create([
                    'order_id' => $lockedOrder->id,
                    'department_id' => $departmentId,
                    'ticket_number' => ProductionTicket::generateTicketNumber((int) $departmentId),
                    'status' => 'pending',
                    'sent_at' => now(),
                    'notes' => $lockedOrder->note,
                ]);

                foreach ($items as $item) {
                    ProductionTicketItem::firstOrCreate(
                        ['order_item_id' => $item->id],
                        [
                            'production_ticket_id' => $ticket->id,
                            'quantity' => (int) ceil((float) $item->quantity),
                            'notes' => $item->notes,
                            'status' => 'pending',
                        ]
                    );
                    $item->update(['sent_to_kitchen_at' => now(), 'is_printed_direct' => true]);
                }
            }

            if (! in_array($lockedOrder->status, ['confirmed', 'in_progress', 'ready'], true)) {
                $lockedOrder->update(['status' => 'confirmed']);
            }
            if ($lockedOrder->dining_table_id && ($table = DiningTable::query()->lockForUpdate()->find($lockedOrder->dining_table_id))) {
                $table->update(['status' => 'OCCUPIED']);
            }

            return $lockedOrder->fresh();
        }, 3);
    }
}
