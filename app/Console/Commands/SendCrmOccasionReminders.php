<?php

namespace App\Console\Commands;

use App\Services\Crm\CrmOccasionReminderService;
use Illuminate\Console\Command;

/**
 * Daily entry point for occasion reminders (routes/console.php). The rule
 * itself lives in CrmOccasionReminderService; it also has a page-load
 * fallback for hosts that never run the scheduler.
 */
class SendCrmOccasionReminders extends Command
{
    protected $signature = 'crm:occasions:remind';
    protected $description = 'Notify the responsible CRM staff about customer/group occasions that fall today';

    public function handle(CrmOccasionReminderService $service): int
    {
        $count = $service->sendDueToday();
        $this->info("Announced {$count} occasion(s) due today.");

        return self::SUCCESS;
    }
}
