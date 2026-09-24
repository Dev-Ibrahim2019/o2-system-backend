<?php

namespace App\Console\Commands;

use App\Services\Integration\TakeawayDispatcher;
use Illuminate\Console\Command;

/** يرسل أحداث إغلاق الطلبات (order.closed) المعلّقة بالـ outbox لبرنامج التيك أواي — راجع TakeawayDispatcher. */
class DispatchTakeawayOrders extends Command
{
    protected $signature = 'takeaway:dispatch';

    protected $description = 'Send pending closed-order events from the outbox to the takeaway system';

    public function handle(TakeawayDispatcher $dispatcher): int
    {
        $result = $dispatcher->dispatchDue();

        $this->info($result['skipped']
            ? 'TAKEAWAY_API_URL غير مضبوط — الأحداث بتضل معلّقة بالـ outbox.'
            : "sent={$result['sent']} failed={$result['failed']}");

        return self::SUCCESS;
    }
}
