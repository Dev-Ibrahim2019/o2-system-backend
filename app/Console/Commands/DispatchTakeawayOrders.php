<?php

namespace App\Console\Commands;

use App\Services\Integration\TakeawayDispatcher;
use Illuminate\Console\Command;

/** يرسل webhooks التيك أواي (order.created/status_changed/driver_assigned/withdrawn) من الـoutbox — راجع TakeawayDispatcher. */
class DispatchTakeawayOrders extends Command
{
    protected $signature = 'takeaway:dispatch';

    protected $description = 'Fan out and deliver pending takeaway webhooks from the outbox (signed, with retry)';

    public function handle(TakeawayDispatcher $dispatcher): int
    {
        $result = $dispatcher->dispatchDue();

        $this->info("fanned_out={$result['fanned_out']} sent={$result['sent']} failed={$result['failed']}"
            .($result['skipped'] ? ' (no active client with a webhook_url)' : ''));

        return self::SUCCESS;
    }
}
