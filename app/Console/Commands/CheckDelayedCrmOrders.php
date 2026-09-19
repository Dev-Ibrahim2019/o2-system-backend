<?php

namespace App\Console\Commands;

use App\Services\Crm\CrmOrderDelayAlertService;
use Illuminate\Console\Command;

/**
 * Scheduled every minute (see routes/console.php) — evaluates every active
 * order against the CRM's configured delay-alert threshold and notifies CRM
 * staff the first time an order crosses it. The actual rule lives in
 * CrmOrderDelayAlertService; this command is only the schedule entry point.
 */
class CheckDelayedCrmOrders extends Command
{
    protected $signature = 'crm:orders:check-delays';
    protected $description = 'Notify CRM staff about active orders that just crossed the configured delay threshold';

    public function handle(CrmOrderDelayAlertService $service): int
    {
        $count = $service->checkAndNotify();

        if ($count > 0) {
            $this->info("Notified CRM staff about {$count} newly delayed order(s).");
        }

        return self::SUCCESS;
    }
}
