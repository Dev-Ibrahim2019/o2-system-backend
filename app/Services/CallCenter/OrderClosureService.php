<?php

namespace App\Services\CallCenter;

use App\Models\Order;
use App\Models\User;
use App\Services\Takeaway\TakeawayOrderService;
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
        private readonly TakeawayOrderService $takeaway,
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

            // بنفس الـtransaction: إما الطلب يتسكّر ويصير متاح للتيك أواي (+ حدث order.created بالـoutbox)، أو ولا
            // واحد. الـwebhook بيطلع بالخلفية (TakeawayDispatcher) مع retry، والتطبيق بيقدر يسحب من الـAPI كمان.
            $this->takeaway->release($locked);

            return $locked->fresh();
        }, 3);
    }
}
