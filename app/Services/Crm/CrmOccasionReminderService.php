<?php

namespace App\Services\Crm;

use App\Models\Customer;
use App\Models\CustomerOccasion;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Notifications\OccasionTodayNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Proactive occasion reminders. Until now the only occasion alert was a
 * birthday notice fired as a side effect of a call-center agent happening to
 * search the customer — an occasion nobody looked up was never announced.
 *
 * Each active occasion whose next occurrence is today notifies:
 *  - its assigned CRM user (assigned_user_id), when set;
 *  - otherwise every crm-manager, so an unassigned occasion is not lost.
 *
 * Deduplicated per occasion per day against the notifications table itself,
 * so the command and the page-load fallback may both run safely.
 */
class CrmOccasionReminderService
{
    /** @return int how many occasions were announced on this run */
    public function sendDueToday(): int
    {
        $today = now()->startOfDay();

        $due = CustomerOccasion::query()
            ->where('is_active', true)
            ->mayOccurBetween($today, $today->copy()->endOfDay())
            ->with(['occasionable', 'assignedUser'])
            ->get()
            ->filter(fn (CustomerOccasion $o) => $o->nextOccurrence($today)?->isSameDay($today));

        if ($due->isEmpty()) {
            return 0;
        }

        $alreadySent = DB::table('notifications')
            ->where('type', OccasionTodayNotification::class)
            ->whereDate('created_at', $today->toDateString())
            ->pluck('data')
            ->map(fn ($data) => json_decode($data, true)['occasion_id'] ?? null)
            ->filter()
            ->unique();

        $managers = null;
        $sent = 0;

        foreach ($due as $occasion) {
            if ($alreadySent->contains($occasion->id)) {
                continue;
            }

            $recipients = $occasion->assignedUser
                ? collect([$occasion->assignedUser])
                : ($managers ??= $this->managers());

            if ($recipients->isEmpty()) {
                continue;
            }

            $owner = $occasion->occasionable;
            $notification = new OccasionTodayNotification(
                (int) $occasion->id,
                (string) $occasion->title,
                $owner?->name,
                $occasion->preferred_contact_method,
            );
            $recipients->each(fn (User $u) => $u->notify($notification));
            $sent++;
        }

        return $sent;
    }

    /**
     * Fallback trigger for hosts where `schedule:run` is not running (the
     * case on this project's machines today): the first CRM screen that
     * calls this each day sends the day's reminders. A cache key keeps it to
     * one attempt per day; the per-occasion dedupe above covers the rest.
     */
    public function sendDueTodayOncePerDay(): void
    {
        if (Cache::add('crm:occasion-reminders:' . now()->toDateString(), true, now()->endOfDay())) {
            $this->sendDueToday();
        }
    }

    private function managers(): Collection
    {
        return User::withoutGlobalScope(BranchScope::class)->role('crm-manager')->get();
    }
}
