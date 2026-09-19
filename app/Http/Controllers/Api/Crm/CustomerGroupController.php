<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Services\Crm\CrmCustomerAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Customer groups — the company, family or agency several customers belong to.
 *
 * Groups are shared structure with no branch of their own: customer_groups has
 * no branch_id, so there is nothing to scope reads by, and access rests on the
 * crm.groups.* permissions alone. Member *listings* are a different matter and
 * are branch-scoped through the customers themselves — see customers() below.
 */
class CustomerGroupController extends Controller
{
    public function __construct(
        private readonly CrmCustomerAccessService $access,
    ) {}

    /**
     * "Paid", the same derived signal every other CRM order screen uses
     * (CrmOrdersQueryService::applyPaymentFilter(), CrmController::
     * transformOrderRow()'s own is_paid comment): status='paid' on the
     * POS/general settlement path, payment_status='paid' on the Call Center
     * path. Neither column alone covers both real write-paths.
     */
    private function applyPaidFilter(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('orders.status', 'paid')->orWhere('orders.payment_status', 'paid'));
    }

    /**
     * A correlated subquery totalling every paid order placed by a member of
     * the group named in the outer query's `customer_groups.id` — used as an
     * addSelect() value, the same shape CrmController::orders() already uses
     * for order_feedback's rating subquery.
     */
    private function totalSpendSubquery(): Builder
    {
        return $this->applyPaidFilter(
            Order::query()
                ->selectRaw('COALESCE(SUM(orders.total), 0)')
                ->whereIn('orders.customer_id', fn ($q) => $q->select('id')->from('customers')->whereColumn('customers.group_id', 'customer_groups.id'))
        );
    }

    private function ordersCountSubquery(): Builder
    {
        return Order::query()
            ->selectRaw('COUNT(*)')
            ->whereIn('orders.customer_id', fn ($q) => $q->select('id')->from('customers')->whereColumn('customers.group_id', 'customer_groups.id'));
    }

    /**
     * GET /api/crm/customer-groups
     *
     * Still the list the customer form's picker reads, now also the list the
     * management screen reads — hence the member count and the two derived
     * financial columns (total_spend, orders_count), both real sums over the
     * existing Order/Customer tables, computed the same way for every group
     * so the list and each group's own show()/analytics() never disagree.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => CustomerGroup::query()
                ->withCount('customers')
                ->addSelect(['total_spend' => $this->totalSpendSubquery(), 'orders_count' => $this->ordersCountSubquery()])
                ->orderBy('name')
                ->get(['id', 'name', 'group_type', 'color', 'created_at'])
                ->each(fn (CustomerGroup $g) => $g->setAttribute('total_spend', (float) $g->total_spend)),
        ]);
    }

    /**
     * GET /api/crm/customer-groups/{group}
     */
    public function show(CustomerGroup $group): JsonResponse
    {
        $group->loadCount('customers');

        // Direct aggregates for one known group — not the correlated
        // subquery shape totalSpendSubquery()/ordersCountSubquery() build for
        // index()'s addSelect(), which only resolves against customer_groups
        // columns present in that outer query's own FROM clause.
        $group->setAttribute('total_spend', (float) $this->applyPaidFilter(
            Order::query()->whereIn('orders.customer_id', fn ($q) => $q->select('id')->from('customers')->where('group_id', $group->id))
        )->sum('orders.total'));

        $group->setAttribute('orders_count', (int) Order::query()
            ->whereIn('orders.customer_id', fn ($q) => $q->select('id')->from('customers')->where('group_id', $group->id))
            ->count());

        return response()->json(['data' => $group]);
    }

    /**
     * GET /api/crm/customer-groups/{group}/analytics
     *
     * The group profile's spending-trend chart — real monthly totals from
     * the same paid-order definition total_spend uses, one point per of the
     * last 6 calendar months (this one included, however partial).
     */
    public function analytics(CustomerGroup $group): JsonResponse
    {
        $months = collect(range(5, 0))->map(fn ($i) => now()->subMonths($i)->startOfMonth());

        $rows = $this->applyPaidFilter(
            Order::query()
                ->selectRaw("DATE_FORMAT(orders.created_at, '%Y-%m') as ym, COALESCE(SUM(orders.total), 0) as total")
                ->whereIn('orders.customer_id', fn ($q) => $q->select('id')->from('customers')->where('group_id', $group->id))
                ->where('orders.created_at', '>=', $months->first())
        )->groupBy('ym')->pluck('total', 'ym');

        $trend = $months->map(fn ($m) => [
            'month' => $m->format('Y-m'),
            'total' => (float) ($rows[$m->format('Y-m')] ?? 0),
        ])->values();

        return response()->json(['data' => ['monthly_spend' => $trend]]);
    }

    /**
     * GET /api/crm/customer-groups/analytics
     *
     * Declared before the {group} wildcard in routes/api.php, same reason
     * as every other literal-before-wildcard route in this API. The
     * cross-group "التحليلات والرؤى" screen's one aggregate: total revenue
     * across every group-linked paid order for the last 6 months, from the
     * identical definition analytics()/total_spend already use — this
     * screen and any one group's own numbers can never disagree.
     */
    public function crossAnalytics(): JsonResponse
    {
        $months = collect(range(5, 0))->map(fn ($i) => now()->subMonths($i)->startOfMonth());

        $rows = $this->applyPaidFilter(
            Order::query()
                ->selectRaw("DATE_FORMAT(orders.created_at, '%Y-%m') as ym, COALESCE(SUM(orders.total), 0) as total")
                ->whereIn('orders.customer_id', fn ($q) => $q->select('id')->from('customers')->whereNotNull('group_id'))
                ->where('orders.created_at', '>=', $months->first())
        )->groupBy('ym')->pluck('total', 'ym');

        $trend = $months->map(fn ($m) => [
            'month' => $m->format('Y-m'),
            'total' => (float) ($rows[$m->format('Y-m')] ?? 0),
        ])->values();

        return response()->json(['data' => ['monthly_spend' => $trend]]);
    }

    /**
     * GET /api/crm/customer-groups/{group}/activity
     *
     * The group's own audit trail (created/updated/deleted) — the same
     * AuditLog table and shape CrmController::activity() already reads for a
     * customer, just keyed to CustomerGroup instead. No call/complaint
     * events here (those are per-customer concepts, not per-group), so this
     * is honestly a shorter list — it does not invent activity that isn't
     * actually logged.
     */
    public function activity(CustomerGroup $group): JsonResponse
    {
        $events = AuditLog::where('auditable_type', CustomerGroup::class)
            ->where('auditable_id', $group->id)
            ->with('user:id,name')
            ->latest()
            ->take(20)
            ->get()
            ->map(fn ($log) => [
                'id' => 'audit-' . $log->id,
                'event' => $log->event,
                'label' => match ($log->event) {
                    'created' => 'تم إنشاء المجموعة',
                    'updated' => 'تم تحديث بيانات المجموعة',
                    'deleted' => 'تم حذف المجموعة',
                    default => $log->event,
                },
                'user' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
                'timestamp' => $log->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $events]);
    }

    /**
     * GET /api/crm/customer-groups/smart-suggestions
     *
     * Three real, rule-based candidate groups computed directly from
     * existing Customer/Order data — no ML model, no external service, no
     * invented numbers. Deliberately not "AI" in the deep-learning sense:
     * every count here is a plain SQL aggregate a manager could reproduce by
     * hand, and `confidence` is a second, stricter pass of the same rule
     * (see each *Matches() method's own comment) rather than a cosmetic
     * percentage — it says how many of the matched customers clear the bar
     * comfortably, not just barely.
     */
    public function smartSuggestions(Request $request): JsonResponse
    {
        $visibleIds = $this->access->visibleCustomers($request->user())->pluck('id');

        $suggestions = collect([
            $this->vipMatches($visibleIds),
            $this->reactivationMatches($visibleIds),
            $this->frequentMatches($visibleIds),
        ])
            ->filter(fn (array $s) => $s['matched_count'] > 0)
            // `ids` is internal — applySmartSuggestion() re-derives it itself
            // rather than trusting a client-echoed list; the public response
            // never needs to carry every matched customer's id.
            ->map(fn (array $s) => collect($s)->except('ids')->all())
            ->values();

        return response()->json(['data' => $suggestions]);
    }

    /**
     * POST /api/crm/customer-groups/smart-suggestions/apply
     *
     * Re-runs the exact same rule server-side (never trusts a client-sent
     * customer list) and, if anyone still matches, creates a real group and
     * assigns every matched customer to it in one transaction — this is a
     * real write, not a preview: reassigns customers.group_id even for a
     * customer already in a different group, the same way dragging them
     * into a new group manually would.
     */
    public function applySmartSuggestion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', Rule::in(['vip_spend', 'reactivation', 'frequent'])],
            'name' => ['required', 'string', 'max:255'],
            'group_type' => ['required', Rule::in(CustomerGroup::TYPES)],
            'color' => ['nullable', Rule::in(CustomerGroup::COLORS)],
        ]);

        $visibleIds = $this->access->visibleCustomers($request->user())->pluck('id');
        $matchedIds = match ($data['key']) {
            'vip_spend' => $this->vipMatches($visibleIds)['ids'],
            'reactivation' => $this->reactivationMatches($visibleIds)['ids'],
            'frequent' => $this->frequentMatches($visibleIds)['ids'],
        };

        abort_if($matchedIds->isEmpty(), 422, 'لا يوجد عملاء مطابقون لهذا الاقتراح حالياً — قد يكون آخر تحديث للبيانات أقدم من الوضع الحالي.');

        $group = DB::transaction(function () use ($data, $matchedIds) {
            $group = CustomerGroup::create([
                'name' => $data['name'],
                'group_type' => $data['group_type'],
                'color' => $data['color'] ?? null,
            ]);
            Customer::whereIn('id', $matchedIds)->update(['group_id' => $group->id]);

            return $group;
        });

        return response()->json(['data' => $group->loadCount('customers')], 201);
    }

    /**
     * "أعلى ٢٥٪ من العملاء إنفاقاً" — the 75th percentile of total paid
     * spend among visible customers with at least one paid order. Confidence
     * is the share of matched customers spending at least 1.5× the
     * threshold — comfortably above the cutoff, not just past it.
     */
    private function vipMatches(Collection $visibleIds): array
    {
        $spend = $this->applyPaidFilter(
            Order::query()->select('customer_id')->selectRaw('SUM(orders.total) as total_spend')
                ->whereIn('orders.customer_id', $visibleIds)
        )->groupBy('customer_id')->pluck('total_spend', 'customer_id')->map(fn ($v) => (float) $v);

        if ($spend->count() < 4) {
            return $this->emptySuggestion('vip_spend');
        }

        $sorted = $spend->values()->sort()->values();
        $threshold = $sorted[(int) floor($sorted->count() * 0.75)];
        if ($threshold <= 0) {
            return $this->emptySuggestion('vip_spend');
        }

        $matched = $spend->filter(fn ($v) => $v >= $threshold);
        $strong = $matched->filter(fn ($v) => $v >= $threshold * 1.5);

        return $this->suggestion(
            key: 'vip_spend',
            title: 'عملاء نشطون بإنفاق مرتفع',
            description: "أعلى 25٪ من عملائك إنفاقاً — إجمالي مشترياتهم المدفوعة " . round($threshold) . '₪ فأكثر.',
            matchedIds: $matched->keys(),
            confidence: $this->confidenceOf($matched, $strong),
            suggestedType: 'retail',
        );
    }

    /**
     * Customers with real order history (2+ past orders — a one-time buyer
     * isn't "churned", they just haven't bought again) whose most recent
     * order is 45+ days old. Confidence is the share with 3+ past orders —
     * a longer real history makes "worth winning back" a safer bet than one
     * customer with exactly two orders.
     */
    private function reactivationMatches(Collection $visibleIds): array
    {
        $stats = Order::query()->select('customer_id')
            ->selectRaw('COUNT(*) as orders_count, MAX(created_at) as last_order_at')
            ->whereIn('customer_id', $visibleIds)
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        $cutoff = now()->subDays(45);
        $matched = $stats->filter(fn ($r) => $r->orders_count >= 2 && $r->last_order_at && \Carbon\Carbon::parse($r->last_order_at)->lt($cutoff));
        $strong = $matched->filter(fn ($r) => $r->orders_count >= 3);

        return $this->suggestion(
            key: 'reactivation',
            title: 'عملاء بحاجة لإعادة تنشيط',
            description: 'عملاء لديهم أكثر من طلب سابق، ولم يطلبوا منذ 45 يوماً على الأقل.',
            matchedIds: $matched->keys(),
            confidence: $this->confidenceOf($matched, $strong),
            suggestedType: 'retail',
        );
    }

    /**
     * Customers with 3+ orders in the last 90 days. Confidence is the share
     * with 5+ — clearly frequent, not just past the minimum bar.
     */
    private function frequentMatches(Collection $visibleIds): array
    {
        $since = now()->subDays(90);
        $counts = Order::query()->select('customer_id')->selectRaw('COUNT(*) as c')
            ->whereIn('customer_id', $visibleIds)
            ->where('created_at', '>=', $since)
            ->groupBy('customer_id')
            ->pluck('c', 'customer_id');

        $matched = $counts->filter(fn ($c) => $c >= 3);
        $strong = $matched->filter(fn ($c) => $c >= 5);

        return $this->suggestion(
            key: 'frequent',
            title: 'عملاء الشراء المتكرر',
            description: 'ثلاثة طلبات فأكثر خلال آخر 90 يوماً — نشاط شراء منتظم يستحق عرضاً مخصصاً.',
            matchedIds: $matched->keys(),
            confidence: $this->confidenceOf($matched, $strong),
            suggestedType: 'retail',
        );
    }

    /** % of $matched that also clears the stricter $strong bar — 0 when nothing matched. */
    private function confidenceOf(Collection $matched, Collection $strong): int
    {
        return $matched->count() > 0 ? (int) round(($strong->count() / $matched->count()) * 100) : 0;
    }

    private function emptySuggestion(string $key): array
    {
        return ['key' => $key, 'matched_count' => 0, 'ids' => collect()];
    }

    /** Shared shape for every suggestion, sample names included so the card never needs a second request. */
    private function suggestion(string $key, string $title, string $description, Collection $matchedIds, int $confidence, string $suggestedType): array
    {
        $sampleNames = Customer::whereIn('id', $matchedIds->take(3))->pluck('name');

        return [
            'key' => $key,
            'title' => $title,
            'description' => $description,
            'matched_count' => $matchedIds->count(),
            'confidence' => $confidence,
            'sample_names' => $sampleNames,
            'suggested_group_type' => $suggestedType,
            'ids' => $matchedIds,
        ];
    }

    /**
     * POST /api/crm/customer-groups
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(required: true));

        return response()->json(['data' => CustomerGroup::create($data)], 201);
    }

    /**
     * PUT /api/crm/customer-groups/{group}
     */
    public function update(Request $request, CustomerGroup $group): JsonResponse
    {
        $group->update($request->validate($this->rules(required: false)));

        return response()->json(['data' => $group->fresh()->loadCount('customers')]);
    }

    /**
     * DELETE /api/crm/customer-groups/{group}
     *
     * Soft delete, with the members detached first.
     *
     * customers.group_id carries ON DELETE SET NULL, but that rule only fires
     * on a real DELETE — a soft delete is an UPDATE, so without this the
     * members would keep pointing at a group that no longer appears anywhere,
     * and the customer form's picker would show them as belonging to nothing
     * it can name. Both writes run in one transaction so a group can never end
     * up deleted with its members still attached, or the reverse.
     *
     * The consequence worth knowing: restoring the group brings back the group,
     * not its membership list. Detaching is what the delete means.
     */
    public function destroy(CustomerGroup $group): JsonResponse
    {
        $detached = DB::transaction(function () use ($group) {
            $count = Customer::where('group_id', $group->id)->update(['group_id' => null]);
            $group->delete();

            return $count;
        });

        return response()->json(['data' => ['deleted' => true, 'detached_customers' => $detached]]);
    }

    /**
     * GET /api/crm/customer-groups/{group}/customers
     *
     * Scoped through visibleCustomers(): a group spans branches, but a reader
     * still only sees the members they could open individually. The group's own
     * customers_count is deliberately unscoped — it describes the group, not
     * the reader's slice of it.
     */
    public function customers(Request $request, CustomerGroup $group): JsonResponse
    {
        $members = $this->access->visibleCustomers($request->user())
            ->where('group_id', $group->id)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'phone', 'branch_id', 'status']);

        return response()->json(['data' => $members]);
    }

    /**
     * POST /api/crm/customer-groups/{group}/customers
     *
     * Writes customers.group_id — the same column the customer form edits.
     * There is no pivot table and no second source: membership is that column,
     * so a change here is immediately what the customer form shows.
     */
    public function addCustomer(Request $request, CustomerGroup $group): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
        ]);

        $customer = Customer::findOrFail($data['customer_id']);

        // A member the reader could not open is a member they must not move.
        $this->access->authorize($request->user(), $customer);

        $customer->update(['group_id' => $group->id]);

        return response()->json([
            'data' => $customer->only(['id', 'name', 'code', 'group_id']),
        ]);
    }

    /**
     * DELETE /api/crm/customer-groups/{group}/customers/{customer}
     *
     * Clears group_id for that one customer only. A customer who is not in
     * this group is a 404 rather than a silent success — otherwise the caller
     * cannot tell "removed" from "was never there", and a wrong id would look
     * like it worked.
     */
    public function removeCustomer(Request $request, CustomerGroup $group, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);

        abort_unless(
            (int) $customer->group_id === (int) $group->id,
            404,
            'هذا العميل ليس عضواً في هذه المجموعة.'
        );

        $customer->update(['group_id' => null]);

        return response()->json(['data' => ['removed' => true]]);
    }

    /** One rule set; only presence differs between create and update. */
    private function rules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string', 'max:255'],
            'group_type' => [$presence, Rule::in(CustomerGroup::TYPES)],
            'color' => ['nullable', Rule::in(CustomerGroup::COLORS)],
        ];
    }
}
