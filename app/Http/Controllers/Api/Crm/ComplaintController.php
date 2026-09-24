<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Models\CustomerComplaint;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Services\CallCenter\CallCenterService;
use App\Services\Crm\CrmCustomerAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRM's cross-customer view of complaints.
 *
 * The Call Center already had a cross-customer index, but it is guarded by
 * call-centre roles and scoped to nothing at all. CRM needs the same data
 * under its own permission, limited to the branch the viewer can see, and
 * with the sensitivity rule applied — so this is a separate read surface over
 * the same table rather than an attempt to widen that one.
 *
 * Writes are deliberately absent: creating and updating a complaint already
 * have CRM entry points on CrmController, both feeding CallCenterService so
 * the lifecycle stays in one place.
 */
class ComplaintController extends Controller
{
    public function __construct(
        private readonly CrmCustomerAccessService $access,
    ) {}

    /**
     * GET /api/crm/complaints
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $this->validateFilters($request);

        $page = $this->scoped($request)
            ->with([
                'customer:id,name,code,phone,branch_id',
                // Lifted out of Employee's global BranchScope for the same
                // reason the picker is: an assignee at another branch is
                // legitimate, and leaving the scope on made the name render
                // blank even though assigned_to held a valid id.
                'assignedTo' => fn ($q) => $q
                    ->withoutGlobalScope(BranchScope::class)
                    ->select('id', 'name', 'branch_id'),
                // The CRM assignee (a user). Branch scope lifted for the same
                // reason as assignedTo — a complaint is routinely worked by
                // staff at another branch.
                'assignedUser' => fn ($q) => $q
                    ->withoutGlobalScope(BranchScope::class)
                    ->select('id', 'name', 'branch_id'),
                'createdBy:id,name',
                'order:id,order_number',
            ])
            ->latest()
            ->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 100));

        return response()->json(['data' => $page]);
    }

    /**
     * GET /api/crm/complaints/summary
     *
     * Counted through the same scoped builder the list uses, so a figure on
     * the summary cards can never describe rows the list below would not
     * show — including sensitive ones the viewer is not cleared for.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->validateFilters($request);

        $countsBy = fn (string $column) => $this->scoped($request)
            ->selectRaw("{$column} as k, COUNT(*) as c")
            ->groupBy($column)
            ->pluck('c', 'k')
            ->all();

        return response()->json([
            'data' => [
                'total' => $this->scoped($request)->count(),
                'open' => $this->scoped($request)->open()->count(),
                'by_status' => $countsBy('status'),
                'by_priority' => $countsBy('priority'),
                'by_channel' => $countsBy('channel'),
                // Uncategorised complaints group under a NULL key, which JSON
                // renders as "". Left as the database reports it rather than
                // folded into a made-up bucket: the caller can tell "not
                // classified" from any real department.
                'by_department' => $countsBy('department'),
                // Same one-line shape as the others. Unassigned complaints
                // group under the NULL key ("") exactly as untagged ones do.
                'by_employee' => $countsBy('assigned_to'),
                // The CRM assignee tally (a user id per key); NULL key ("") is
                // "unassigned". This is the one the CRM screens read.
                'by_assigned_user' => $countsBy('assigned_user_id'),
            ],
        ]);
    }

    /**
     * GET /api/crm/complaints/{complaint}
     *
     * Carries the followup trail inline — the same data the Call Center's
     * timeline endpoint returns, which CRM roles cannot reach.
     */
    public function show(Request $request, CustomerComplaint $complaint): JsonResponse
    {
        // Resolved through the scoped query rather than checked afterwards.
        // A complaint outside the viewer's branch, or a sensitive one they
        // are not cleared for, comes back as a plain 404 — the same answer
        // as an id that does not exist. A 403 here would confirm the record
        // is real, which for a sensitive complaint is itself the disclosure.
        $found = $this->scoped($request, applyFilters: false)
            ->whereKey($complaint->getKey())
            ->with([
                'customer:id,name,code,phone,branch_id',
                // Lifted out of Employee's global BranchScope for the same
                // reason the picker is: an assignee at another branch is
                // legitimate, and leaving the scope on made the name render
                // blank even though assigned_to held a valid id.
                'assignedTo' => fn ($q) => $q
                    ->withoutGlobalScope(BranchScope::class)
                    ->select('id', 'name', 'branch_id'),
                'assignedUser' => fn ($q) => $q
                    ->withoutGlobalScope(BranchScope::class)
                    ->select('id', 'name', 'branch_id'),
                'createdBy:id,name',
                'order:id,order_number',
                // Branch scope lifted here too: the follow-up trail must name
                // who did what even when that person sits at another branch —
                // without this the actor silently renders as "—".
                'followups.user' => fn ($q) => $q
                    ->withoutGlobalScope(BranchScope::class)
                    ->select('id', 'name'),
            ])
            ->first();

        abort_if($found === null, 404, 'الشكوى غير موجودة.');

        return response()->json([
            'data' => $found,
            'followups' => $found->followups->sortByDesc('created_at')->values(),
        ]);
    }

    /**
     * GET /api/crm/complaints/assignable-users
     *
     * The real login accounts a CRM complaint may be assigned to — anyone
     * holding crm.complaints.update (the permission to work a complaint at
     * all). Branch scope lifted: a complaint filed at one branch is regularly
     * worked by staff at another, so the picker cannot be limited to the
     * viewer's own branch the way operational screens are.
     */
    public function assignableUsers(Request $request): JsonResponse
    {
        $users = User::withoutGlobalScope(BranchScope::class)
            ->permission('crm.complaints.update')
            ->with('branch:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'branch_id'])
            ->values();

        return response()->json(['data' => $users]);
    }

    /**
     * POST /api/crm/complaints/{complaint}/followups
     *
     * CRM's own door onto CallCenterService::addFollowup() — the same method
     * the Call Center route calls, so the trail stays one shape written by one
     * piece of logic. Only the guard differs: crm.complaints.update instead of
     * the call-centre role group.
     */
    public function addFollowup(
        Request $request,
        CustomerComplaint $complaint,
        CallCenterService $callCenter,
        \App\Services\Crm\ComplaintNotificationService $notifications,
    ): JsonResponse {
        // Resolved through the scoped builder, exactly as show() does: a
        // complaint the viewer could not open must not be one they can append
        // to, and a 404 keeps a sensitive record's existence unconfirmed.
        $found = $this->scoped($request, applyFilters: false)
            ->whereKey($complaint->getKey())
            ->first();

        abort_if($found === null, 404, 'الشكوى غير موجودة.');

        $data = $request->validate([
            'notes' => ['required', 'string', 'max:2000'],
            'action' => ['nullable', 'string', 'max:50'],
            'followup_type' => ['nullable', 'in:note,call,action,system'],
        ]);

        $followup = $callCenter->addFollowup(
            $found->id,
            $request->user()->id,
            $data['action'] ?? 'note_added',
            $data['notes'],
            $data['followup_type'] ?? 'note',
        );

        // Tell the assignee someone else wrote on their ticket — the one
        // follow-up scenario worth a bell: a manager or a colleague added
        // context the assignee has not seen yet.
        if ($found->assigned_user_id && (int) $found->assigned_user_id !== $request->user()->id) {
            $notifications->notifyUser(
                (int) $found->assigned_user_id,
                $found,
                $request->user(),
                'followup_added',
                "أضاف {$request->user()->name} متابعة على الشكوى #{$found->id}: {$found->title}",
            );
        }

        return response()->json(['data' => $followup->load('user:id,name')], 201);
    }

    /**
     * The one builder all three endpoints read through: branch scope,
     * sensitivity scope, then the optional filters.
     */
    private function scoped(Request $request, bool $applyFilters = true): Builder
    {
        $filters = $applyFilters ? $this->validateFilters($request) : [];

        $user = $request->user();

        return CustomerComplaint::query()
            ->visibleTo($user)
            // A row is visible either through its customer (the normal case)
            // or, for a "شكوى عامة" with no customer_id, through its own
            // branch_id — the same two paths updateComplaint()'s authorization
            // checks below.
            ->where(function (Builder $q) use ($user) {
                $q->where(function (Builder $customerLinked) use ($user) {
                    $customerLinked->whereIn('customer_id', $this->access->visibleCustomers($user)->select('id'));
                    // Customer identity is global by design (visibleCustomers()
                    // is unfiltered above), but a complaint is operational —
                    // it belongs to a branch. Previously this branch was never
                    // actually checked here, so a branch-scoped user saw every
                    // customer-linked complaint from both branches. Now
                    // restricted to: their own branch, a legacy row with no
                    // branch_id yet (pre-fix data), or one they're personally
                    // the current assignee on — the last clause is what keeps
                    // a cross-branch-assigned employee able to see a complaint
                    // they were deliberately handed from another branch.
                    // Note: assigned_to (Employee FK, the Call Center's field) is
                    // deliberately NOT checked here — User and Employee are two
                    // entirely separate, unlinked accounts in this codebase (no
                    // employee_id on User, no user_id on Employee), so a logged-in
                    // User can never be reliably matched against assigned_to.
                    if (! $this->access->isGlobal($user)) {
                        $customerLinked->where(function (Builder $b) use ($user) {
                            $b->whereNull('branch_id')
                                ->orWhere('branch_id', (string) $user->branch_id)
                                ->orWhere('assigned_user_id', $user->id);
                        });
                    }
                })
                    ->orWhere(function (Builder $general) use ($user) {
                        $general->whereNull('customer_id');
                        if (! $this->access->isGlobal($user)) {
                            $general->where(function (Builder $b) use ($user) {
                                $b->whereNull('branch_id')->orWhere('branch_id', (string) $user->branch_id);
                            });
                        }
                    });
            })
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            // "الشكاوى المفتوحة" view — the happy-path statuses CustomerComplaint
            // ::scopeOpen() already defines, reused so this can never drift
            // from what the "شكاوى مفتوحة" KPI card counts.
            ->when(($filters['view'] ?? null) === 'open', fn ($q) => $q->open())
            ->when(($filters['view'] ?? null) === 'general', fn ($q) => $q->whereNull('customer_id'))
            ->when($filters['priority'] ?? null, fn ($q, $v) => $q->where('priority', $v))
            ->when($filters['channel'] ?? null, fn ($q, $v) => $q->where('channel', $v))
            ->when($filters['department'] ?? null, fn ($q, $v) => $q->where('department', $v))
            // No exemption from the scopes above: this narrows the already
            // branch- and sensitivity-filtered set, it does not reach past it.
            ->when($filters['assigned_to'] ?? null, fn ($q, $v) => $q->where('assigned_to', $v))
            // The CRM assignee filter. Accepts a user id, or the two words the
            // toolbar offers: "mine" (assigned to me) and "none" (unassigned).
            ->when($filters['assigned_user_id'] ?? null, function (Builder $q, string $v) use ($request) {
                match ($v) {
                    'mine' => $q->where('assigned_user_id', $request->user()->id),
                    'none' => $q->whereNull('assigned_user_id'),
                    default => $q->where('assigned_user_id', (int) $v),
                };
            })
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filters['search'] ?? null, function (Builder $q, string $term) {
                // Title and description on the complaint, name and phone on
                // the customer — an agent looking up "who complained" has the
                // caller's number far more often than the ticket's wording.
                $q->where(function (Builder $inner) use ($term) {
                    $inner->where('title', 'like', "%{$term}%")
                        ->orWhere('description', 'like', "%{$term}%")
                        ->orWhereHas('customer', fn (Builder $c) => $c
                            ->where('name', 'like', "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%"));
                });
            });
    }

    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'status' => ['nullable', Rule::in([
                CustomerComplaint::STATUS_NEW,
                CustomerComplaint::STATUS_OPEN,
                CustomerComplaint::STATUS_IN_PROGRESS,
                CustomerComplaint::STATUS_WAITING_CUSTOMER,
                CustomerComplaint::STATUS_RESOLVED,
                CustomerComplaint::STATUS_CLOSED,
                CustomerComplaint::STATUS_CANCELLED,
            ])],
            'priority' => ['nullable', Rule::in([
                CustomerComplaint::PRIORITY_LOW,
                CustomerComplaint::PRIORITY_NORMAL,
                CustomerComplaint::PRIORITY_HIGH,
                CustomerComplaint::PRIORITY_CRITICAL,
            ])],
            // "open": the happy-path statuses (new/open/in_progress/waiting_
            // customer) — what /admin/crm/complaints/open shows. "general":
            // complaints with no customer_id.
            'view' => ['nullable', Rule::in(['open', 'general'])],
            'channel' => ['nullable', Rule::in(CustomerComplaint::CHANNELS)],
            'department' => ['nullable', Rule::in(CustomerComplaint::DEPARTMENTS)],
            'assigned_to' => ['nullable', 'integer', 'exists:employees,id'],
            // A user id, or the literals "mine" / "none". Kept loose on
            // purpose — scoped() maps the three shapes.
            'assigned_user_id' => ['nullable', 'string', 'max:20'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }
}
