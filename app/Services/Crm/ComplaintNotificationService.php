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
     * Notify the handlers of a complaint — every new complaint, not only
     * urgent ones (see CallCenterService::createComplaint(), the single
     * shared creation path both CRM and Call Center funnel through). Urgent
     * complaints (high/critical priority, or critical severity) use this
     * with more insistent wording so whoever is free grabs it immediately
     * instead of waiting its turn in the normal queue; everything else still
     * reaches the same audience, just phrased as a normal arrival.
     *
     * Branch-scoped as of the branch-ownership fix: complaints.branch_id is
     * now reliably stamped at creation (order's branch, or the filing
     * agent's own branch — see CallCenterService::createComplaint()), so
     * this reaches only crm.complaints.update holders at that SAME branch,
     * plus global users (super-admin / null branch_id), plus whoever is
     * currently the CRM assignee regardless of their own branch (a
     * cross-branch-assigned handler must still be told). A complaint that
     * still has a null branch_id (a legacy row from before this fix, or a
     * branch-less actor filing with no order) falls back to the previous
     * company-wide broadcast — there is nothing to scope it to.
     */
    public function notifyAllHandlers(CustomerComplaint $complaint, User $actor, string $message, bool $urgent = false): void
    {
        $query = User::withoutGlobalScope(BranchScope::class)->permission('crm.complaints.update');

        if ($complaint->branch_id !== null) {
            $branchId = $complaint->branch_id;
            $assignedUserId = $complaint->assigned_user_id;
            $query->where(function ($q) use ($branchId, $assignedUserId) {
                $q->whereNull('branch_id')
                    ->orWhere('branch_id', $branchId);
                if ($assignedUserId) {
                    $q->orWhere('id', $assignedUserId);
                }
            });
        }

        $query->get()
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
