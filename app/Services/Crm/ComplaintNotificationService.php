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
