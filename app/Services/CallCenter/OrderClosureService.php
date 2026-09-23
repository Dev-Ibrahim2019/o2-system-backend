<?php

namespace App\Services\CallCenter;

use App\Models\Order;
use App\Models\PaymentConfirmation;
use App\Models\User;
use App\Services\Integration\IntegrationOutboxWriter;
use App\Services\Integration\TakeawayDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * إغلاق طلب الكول سنتر (F7): مسموح فقط لما الطلب مدفوع ومنفّذ. بعد الإغلاق بتتحرر خانته (observer
 * على الطلب → OrderSlotService) وبيتابعه موظف التيك أواي.
 *
 * منفصل عن OrderStatusService::maybeAutoClose/forceComplete (مسار التسليم الداخلي القديم اللي بيسكّر
 * بعد served/DELIVERED) — هاد الإغلاق اليدوي بيحصل قبل التوصيل، لأنه التوصيل صار على برنامج التيك أواي.
 */
class OrderClosureService
{
    public function __construct(
        private readonly OrderStatusService $statusService,
        private readonly IntegrationOutboxWriter $outbox,
    ) {}

    public function close(Order $order, User $by): Order
    {
        return DB::transaction(function () use ($order, $by) {
            $locked = Order::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);

            if ($locked->source !== 'call_center') {
                throw ValidationException::withMessages(['order' => ['هذا الإجراء لطلبات الكول سنتر فقط.']]);
            }

            $lifecycle = OrderFlowService::lifecycle($locked);
            if ($lifecycle === 'closed') {
                return $locked; // ضغطتين F7 ورا بعض: idempotent
            }
            if ($lifecycle === 'cancelled') {
                throw ValidationException::withMessages(['order' => ['لا يمكن إغلاق طلب ملغى.']]);
            }

            $blockers = OrderFlowService::closeBlockers($locked);
            if ($blockers !== []) {
                throw ValidationException::withMessages(['order' => ['لا يمكن إغلاق الطلب: '.implode(' و', $blockers).'.']]);
            }

            $from = $locked->status;
            $locked->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $by->id]);
            $this->statusService->logStatusChange($locked, $from, 'closed', 'closed', 'إغلاق الطلب — مدفوع ومنفّذ');

            // بنفس الـtransaction: إما الطلب يتسكّر والحدث يتسجّل، أو ولا واحد. الإرسال الفعلي للتيك أواي
            // بيصير بالخلفية (TakeawayDispatcher) مع إعادة محاولة، فما بيضيع لو برنامجهم كان واقف.
            $this->outbox->record(
                eventType: TakeawayDispatcher::EVENT,
                aggregateType: 'order',
                aggregateRef: $locked->public_ref,
                payload: $this->takeawayPayload($locked),
            );

            return $locked->fresh();
        }, 3);
    }

    private function takeawayPayload(Order $order): array
    {
        $order->loadMissing(['items', 'branch:id,name']);
        $payment = PaymentConfirmation::query()->where('order_id', $order->id)->latest('id')->first();

        return [
            'public_order_ref' => $order->public_ref,
            'order_number' => $order->order_number,
            'source' => $order->source,
            'order_type' => $order->order_type,
            'branch' => ['id' => $order->branch_id, 'name' => $order->branch?->name],
            'customer' => ['name' => $order->customer_name, 'phone' => $order->customer_phone],
            'delivery' => [
                'address' => $order->delivery_address_snapshot,
                'notes' => $order->delivery_notes,
                'fee' => (float) $order->delivery_fee,
            ],
            'items' => $order->items->where('status', '!=', 'cancelled')->map(fn ($item) => [
                'name' => $item->item_name_ar ?: $item->item_name,
                'quantity' => (float) $item->quantity,
                'notes' => $item->notes,
            ])->values()->all(),
            'totals' => ['subtotal' => (float) $order->subtotal, 'discount' => (float) $order->discount_amount, 'total' => (float) $order->total],
            'payment' => [
                'status' => 'paid',
                'reference_number' => $payment?->reference_number,
                'transferred_at' => $payment?->transferred_at?->toDateString(),
            ],
            'notes' => $order->note,
            'scheduled_at' => $order->scheduled_at?->toIso8601String(),
            'closed_at' => $order->closed_at?->toIso8601String(),
        ];
    }
}
