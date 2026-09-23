<?php

namespace App\Console\Commands;

use App\Services\CallCenter\OrderSlotService;
use Illuminate\Console\Command;

/**
 * شبكة أمان دورية لخانات طلبات الكول سنتر: بتحرّر خانات الطلبات اللي خلص دورها بطريقة تخطّت الـobservers،
 * وبتعطي خانات للطلبات المنتظرة بالطابور بعد ما تخلص فترة الـcooldown للخانات المحررة.
 */
class ReconcileOrderSlots extends Command
{
    protected $signature = 'call-center:slots:reconcile';

    protected $description = 'Release finished call-center order slots and assign free slots to queued orders';

    public function handle(OrderSlotService $slots): int
    {
        $result = $slots->reconcile();
        $this->info("released={$result['released']} reserved={$result['reserved']}");

        return self::SUCCESS;
    }
}
