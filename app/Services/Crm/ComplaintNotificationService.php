<?php

namespace App\Services\Crm;

use App\Models\CustomerComplaint;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Notifications\ComplaintActivityNotification;

/**
 * Every place a complaint event needs to reach someone's bell — one place,
 * so CrmController (assignment, resolve/cancel) and Crm\ComplaintController
 * (follow-ups, creation) fire the same two shapes of notification instead of
 * each keeping its own copy.
 *
 * Both branch scope lifts (a recipient or an oversight manager may sit at
 * another branch — a complaint is routinely worked across branches) and the
 * "never notify the actor about their own action" rule live here once.
 */
class ComplaintNotificationService
{
    /** Notify one specific user about a complaint (branch scope lifted to find them). */
    public function notifyUser(int $userId, CustomerComplaint $complaint, User $actor, string $action, string $message): void
    {
        if ($userId === $actor->id) {
            return;
        }

        $user = User::withoutGlobalScope(BranchScope::class)->find($userId);
        $user?->notify(new ComplaintActivityNotification(
            (int) $complaint->id,
            (string) $complaint->title,
            $action,
            $actor->name,
            $message,
        ));
    }

    /**
     * Broadcast a complaint to everyone who can work one — every new
     * complaint, not only urgent ones (see CallCenterService::createComplaint(),
     * the single shared creation path both CRM and Call Center funnel
     * through). Urgent complaints (high/critical priority, or critical
     * severity) use this with more insistent wording so whoever is free
     * grabs it immediately instead of waiting its turn in the normal queue;
     * everything else still reaches the same audience, just phrased as a
     * normal arrival — a normal-priority complaint used to notify nobody at
     * all when filed through the Call Center, and only crm-manager role
     * holders when filed through CRM.
     *
     * Company-wide, not branch-scoped: customer_complaints.branch_id is
     * frequently null even for a real per-customer complaint (the per-
     * customer creation path never sets it), so scoping this by branch would
     * silently under-notify on the common case.
     */
    public function notifyAllHandlers(CustomerComplaint $complaint, User $actor, string $message, bool $urgent = false): void
    {
        User::withoutGlobalScope(BranchScope::class)
            ->permission('crm.complaints.update')
            ->get()
            ->reject(fn (User $u) => $u->id === $actor->id)
            ->each(fn (User $u) => $u->notify(new ComplaintActivityNotification(
                (int) $complaint->id,
                (string) $complaint->title,
                $urgent ? 'urgent' : 'created',
                $actor->name,
                $message,
            )));
    }

    /**
     * Notify the people who watch complaints regardless of who works them:
     * every CRM manager, plus whoever filed this one — never the actor.
     */
    public function notifyOversight(CustomerComplaint $complaint, User $actor, string $action, string $message): void
    {
        $recipients = User::withoutGlobalScope(BranchScope::class)
            ->role('crm-manager')
            ->get();

        if ($complaint->created_by && ! $recipients->contains('id', (int) $complaint->created_by)) {
            $creator = User::withoutGlobalScope(BranchScope::class)->find($complaint->created_by);
            if ($creator) {
                $recipients->push($creator);
            }
        }

        $recipients
            ->reject(fn (User $u) => $u->id === $actor->id)
            ->each(fn (User $u) => $u->notify(new ComplaintActivityNotification(
                (int) $complaint->id,
                (string) $complaint->title,
                $action,
                $actor->name,
                $message,
            )));
    }
}
