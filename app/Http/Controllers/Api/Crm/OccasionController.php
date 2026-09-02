<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\CustomerOccasion;
use App\Models\OccasionFollowup;
use App\Models\User;
use App\Services\Crm\CrmCustomerAccessService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cross-owner occasion reads and the yearly diary.
 *
 * Kept out of CrmController for the same reason ComplaintController is: the
 * per-owner CRUD there is addressed under /customers/{customer} or
 * /groups/{group}, while everything here is addressed by occasion id alone or
 * spans both owner types at once.
 */
class OccasionController extends Controller
{
    /** The columns nextOccurrence() and the owner lookup need, nothing more. */
    private const SCOPE_COLUMNS = ['id', 'date', 'repeats_annually', 'occasionable_type', 'occasionable_id'];

    public function __construct(private CrmCustomerAccessService $access) {}

    /**
     * GET /api/crm/occasions
     *
     * Every occasion whose *next* occurrence lands inside the window, across
     * both owner types — the list summary() counts and the one the calendar
     * paints. Until this existed the only cross-owner listing lived behind the
     * Call Center role group, where a crm-manager was answered 403.
     *
     * `range` reuses the windows summary() uses, which are the windows
     * CallCenterService::getOccasionsByRange() uses. `from`/`to` overrides them
     * for a calendar navigating to an arbitrary month; nextOccurrence($from)
     * rolls relative to that month, so an annual occasion appears in the month
     * it actually falls in and in no other.
     *
     * Owner name and phone are resolved here rather than left to the caller: a
     * list of thirty occasions must not become thirty follow-up requests to
     * draw a call button.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'range' => ['nullable', Rule::in(['today', 'week', 'month', 'upcoming'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'owner_type' => ['nullable', Rule::in(['customer', 'group'])],
            'occasion_type' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $today = now()->startOfDay();

        // An explicit from/to wins over range; with neither, "upcoming" is the
        // honest default for a screen that opens on "what is coming".
        if (isset($validated['from']) || isset($validated['to'])) {
            $start = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : $today;
            $end = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : null;
        } else {
            [$start, $end] = $this->rangeWindow($validated['range'] ?? 'upcoming', $today);
        }

        $ownerClass = match ($validated['owner_type'] ?? null) {
            'customer' => Customer::class,
            'group' => CustomerGroup::class,
            default => null,
        };

        $rows = $this->scopedQuery($request->user())
            ->when($ownerClass, fn ($q) => $q->where('occasionable_type', $ownerClass))
            ->when(
                $validated['occasion_type'] ?? null,
                fn ($q, $type) => $q->where('occasion_type', $type)
            )
            // The owner is loaded for its name and phone. A morphTo cannot
            // select columns per type, so the nested constraint is declared
            // through morphWith on the one type that has phones.
            ->with(['occasionable' => fn ($morph) => $morph->morphWith([
                Customer::class => ['primaryPhone:id,customer_id,phone,normalized_phone'],
            ])])
            ->get()
            ->filter(fn (CustomerOccasion $o) => $this->fallsWithin($o, $start, $end))
            ->map(fn (CustomerOccasion $o) => $this->row($o, $start))
            // Sorted by when it actually falls, not by the stored date — the
            // stored date is a birth year and orders the list meaninglessly.
            ->sortBy('next_occurrence')
            ->values();

        // Deliberately simple: a window of occasions is small by nature and the
        // calendar wants a whole month at once, so the default page holds the
        // maximum. The slice exists only so a pathological dataset cannot
        // return unbounded rows.
        $perPage = (int) ($validated['per_page'] ?? 200);
        $page = (int) ($validated['page'] ?? 1);

        return response()->json([
            'data' => $rows->forPage($page, $perPage)->values(),
            'meta' => [
                'total' => $rows->count(),
                'per_page' => $perPage,
                'current_page' => $page,
                'from' => $start->toDateString(),
                'to' => $end?->toDateString(),
            ],
        ]);
    }

    /**
     * GET /api/crm/occasions/summary
     *
     * Declared before the {occasion} wildcard in routes/api.php, or "summary"
     * is looked up as an occasion id.
     *
     * Counts are nested — an occasion falling today is counted in all three —
     * and every window is closed by the *next* occurrence, so a birthday
     * stored as 1993-05-05 counts in May of this year. Reading the raw column
     * would report zero for it, which is the exact bug the unified
     * nextOccurrence() was introduced to end.
     *
     * Shares scopedQuery(), windowEnds() and fallsWithin() with index(), so a
     * count here and the rows there cannot disagree.
     */
    public function summary(Request $request): JsonResponse
    {
        $today = now()->startOfDay();
        $occasions = $this->scopedQuery($request->user())->get(self::SCOPE_COLUMNS);
        $ends = $this->windowEnds($today);

        $counts = [];
        foreach ($ends as $key => $end) {
            $counts[$key] = $occasions
                ->filter(fn (CustomerOccasion $o) => $this->fallsWithin($o, $today, $end))
                ->count();
        }

        return response()->json([
            'data' => $counts + [
                // Named so a caller does not have to guess whether "this_week"
                // means the calendar week or the next seven days.
                'window_ends' => [
                    'today' => $ends['today']->toDateString(),
                    'this_week' => $ends['this_week']->toDateString(),
                    'this_month' => $ends['this_month']->toDateString(),
                ],
            ],
        ]);
    }

    /**
     * GET /api/crm/occasions/{occasion}
     *
     * The single-occasion read did not exist: an occasion could only be seen
     * inside its owner's list, which cannot carry the followup log.
     */
    public function show(Request $request, CustomerOccasion $occasion): JsonResponse
    {
        $this->authorizeOccasion($request, $occasion);

        $occasion->load(['occasionable', 'creator:id,name', 'followups.creator:id,name']);

        return response()->json([
            'data' => array_merge($occasion->toArray(), [
                // Derived, not stored — the same rolled date every other
                // occasion surface shows, so the detail view and the lists
                // cannot disagree about when this next falls.
                'next_occurrence' => optional($occasion->nextOccurrence())->toDateString(),
                'days_until_next' => $occasion->daysUntilNext(),
            ]),
        ]);
    }

    /**
     * POST /api/crm/occasions/{occasion}/followups
     *
     * Rides on crm.occasions.update (declared on the route): writing the
     * diary is a write on the occasion, and whoever may edit the occasion may
     * record what was done for it. No new permission — the identical treatment
     * complaint followups get.
     */
    public function addFollowup(Request $request, CustomerOccasion $occasion): JsonResponse
    {
        $this->authorizeOccasion($request, $occasion);

        $data = $request->validate([
            'notes' => ['required', 'string', 'max:5000'],
        ]);

        $followup = OccasionFollowup::create([
            'occasion_id' => $occasion->id,
            'notes' => $data['notes'],
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'data' => $followup->load('creator:id,name'),
        ], 201);
    }

    /**
     * The one scoped builder both listing endpoints read.
     *
     * Branch scope applies to customer-owned occasions only. Groups carry no
     * branch_id, so there is nothing to scope them by — the same decision
     * CustomerGroupController@index and CrmController@groupOccasions record.
     * Extracted so that a count from summary() and the rows from index()
     * cannot drift into disagreeing about who may see what.
     */
    private function scopedQuery(User $user): Builder
    {
        $visibleCustomerIds = $this->access->isGlobal($user)
            ? null
            : $this->access->visibleCustomers($user)->pluck('id');

        return CustomerOccasion::query()
            ->where('is_active', true)
            ->when($visibleCustomerIds !== null, fn ($q) => $q->where(
                fn ($w) => $w
                    ->where(fn ($c) => $c
                        ->where('occasionable_type', Customer::class)
                        ->whereIn('occasionable_id', $visibleCustomerIds))
                    ->orWhere('occasionable_type', '!=', Customer::class)
            ));
    }

    /** The nested window ends summary() reports, closed on the same dates. */
    private function windowEnds(Carbon $today): array
    {
        return [
            'today' => $today->copy(),
            'this_week' => $today->copy()->endOfWeek(),
            'this_month' => $today->copy()->endOfMonth(),
        ];
    }

    /**
     * A named range as [start, end]; `upcoming` is open-ended.
     *
     * The same three closes windowEnds() uses, which are the ones
     * CallCenterService::getOccasionsByRange() uses — so ?range=month returns
     * exactly summary.this_month rows.
     */
    private function rangeWindow(string $range, Carbon $today): array
    {
        $ends = $this->windowEnds($today);

        return match ($range) {
            'today' => [$today->copy(), $ends['today']],
            'week' => [$today->copy(), $ends['this_week']],
            'month' => [$today->copy(), $ends['this_month']],
            default => [$today->copy(), null],
        };
    }

    /** Whether the next occurrence from $start lands at or before $end. */
    private function fallsWithin(CustomerOccasion $occasion, Carbon $start, ?Carbon $end): bool
    {
        $next = $occasion->nextOccurrence($start);

        return $next !== null && ($end === null || $next->lte($end));
    }

    /**
     * One list row: the occasion, its rolled date, and enough of its owner to
     * draw a name and a contact button.
     */
    private function row(CustomerOccasion $occasion, Carbon $from): array
    {
        $owner = $occasion->occasionable;
        $isCustomer = $owner instanceof Customer;

        return [
            'id' => $occasion->id,
            'occasion_type' => $occasion->occasion_type,
            'title' => $occasion->title,
            'date' => optional($occasion->date)->toDateString(),
            'repeats_annually' => (bool) $occasion->repeats_annually,
            'next_occurrence' => optional($occasion->nextOccurrence($from))->toDateString(),
            'owner_type' => $isCustomer ? 'customer' : 'group',
            'owner_id' => $occasion->occasionable_id,
            'owner_name' => $owner?->name,
            // A group has no number to dial — reaching its members is the
            // deferred bulk-messaging module, not one member's phone. For a
            // customer the normalized E.164 value is preferred because that is
            // what a wa.me link needs; the legacy column is the fallback for a
            // record with no customer_phones row yet, so an older customer
            // still gets a working tel: button.
            'owner_phone' => $isCustomer
                ? ($owner->primaryPhone?->normalized_phone ?? $owner->phone ?? $owner->mobile)
                : null,
        ];
    }

    /**
     * Branch check for an occasion addressed by its own id.
     *
     * The owner is resolved first because the URL no longer names it. A
     * customer-owned occasion goes through the same branch guard the nested
     * routes apply; a group-owned one has no branch to check, so the route's
     * permission is the whole guard.
     */
    private function authorizeOccasion(Request $request, CustomerOccasion $occasion): void
    {
        $owner = $occasion->occasionable;

        if ($owner instanceof Customer) {
            $this->access->authorize($request->user(), $owner);
        }
    }
}
