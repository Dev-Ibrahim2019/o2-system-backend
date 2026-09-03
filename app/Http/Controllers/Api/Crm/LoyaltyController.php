<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\LoyaltyRule;
use App\Models\LoyaltyTransaction;
use App\Services\Crm\CrmCustomerAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Balances, ledger reads, and manual adjustments over loyalty_transactions.
 *
 * Read-only over history: nothing here ever updates a ledger row.
 * assertNotTheOnlyBaseRule and LoyaltyEngine both stay untouched — this is
 * strictly the surface the frontend reads and posts adjustments through.
 */
class LoyaltyController extends Controller
{
    private const TYPES = ['earn', 'redeem', 'referral_bonus', 'manual_adjustment', 'campaign_reversal'];

    public function __construct(private CrmCustomerAccessService $access) {}

    /**
     * GET /api/crm/customers/{customer}/loyalty/summary
     */
    public function customerSummary(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);

        return response()->json(['data' => $this->ownerSummary('customer', $customer->id)]);
    }

    /**
     * GET /api/crm/customers/{customer}/loyalty/transactions
     */
    public function customerTransactions(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);

        return response()->json(['data' => $this->ownerTransactions($request, 'customer', $customer->id)]);
    }

    /**
     * GET /api/crm/groups/{group}/loyalty/summary
     *
     * No branch check: customer_groups carries no branch_id, the same
     * decision recorded on every other group-owned read in this module.
     */
    public function groupSummary(CustomerGroup $group): JsonResponse
    {
        return response()->json(['data' => $this->ownerSummary('group', $group->id)]);
    }

    /**
     * GET /api/crm/groups/{group}/loyalty/transactions
     */
    public function groupTransactions(Request $request, CustomerGroup $group): JsonResponse
    {
        return response()->json(['data' => $this->ownerTransactions($request, 'group', $group->id)]);
    }

    /**
     * GET /api/crm/loyalty/transactions
     *
     * Cross-owner listing for the standalone screen, mirroring
     * GET /crm/occasions's own shape. `search` resolves against customer
     * name/code and group name first, then filters the ledger by the
     * resulting owner ids — the two owner tables cannot be joined directly
     * since owner_id is not a real foreign key (it points at whichever table
     * owner_type names).
     *
     * Customer-owned rows are scoped to the caller's branch via
     * visibleCustomers(); group-owned rows are not, matching every other
     * cross-owner CRM listing in this module.
     */
    public function transactions(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'owner_type' => ['nullable', Rule::in(['customer', 'group'])],
            'type' => ['nullable', Rule::in(self::TYPES)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $visibleCustomerIds = $this->access->isGlobal($user)
            ? null
            : $this->access->visibleCustomers($user)->pluck('id');

        $query = LoyaltyTransaction::query()
            ->when($filters['owner_type'] ?? null, fn ($q, $type) => $q->where('owner_type', $type))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            // Branch scope on customer-owned rows only; group-owned rows have
            // no branch to check, so they pass through untouched.
            ->when($visibleCustomerIds !== null, fn ($q) => $q->where(
                fn (Builder $w) => $w
                    ->where(fn (Builder $c) => $c
                        ->where('owner_type', 'customer')
                        ->whereIn('owner_id', $visibleCustomerIds))
                    ->orWhere('owner_type', 'group')
            ));

        if ($search = $filters['search'] ?? null) {
            $customerIds = Customer::withoutGlobalScopes()
                ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"))
                ->pluck('id');
            $groupIds = CustomerGroup::where('name', 'like', "%{$search}%")->pluck('id');

            $query->where(
                fn (Builder $w) => $w
                    ->where(fn (Builder $c) => $c->where('owner_type', 'customer')->whereIn('owner_id', $customerIds))
                    ->orWhere(fn (Builder $c) => $c->where('owner_type', 'group')->whereIn('owner_id', $groupIds))
            );
        }

        $page = $query->with(['rule:id,name', 'creator:id,name'])
            ->latest('created_at')
            ->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 100));

        // The owner's display name does not live on this table (owner_id is
        // not a real FK), so it is resolved here for exactly the rows on this
        // page rather than joined — the same reasoning the occasions listing
        // uses for its owner lookup.
        $customerNames = Customer::withoutGlobalScopes()
            ->whereIn('id', $page->getCollection()->where('owner_type', 'customer')->pluck('owner_id'))
            ->pluck('name', 'id');
        $groupNames = CustomerGroup::whereIn('id', $page->getCollection()->where('owner_type', 'group')->pluck('owner_id'))
            ->pluck('name', 'id');

        $page->getCollection()->transform(function (LoyaltyTransaction $t) use ($customerNames, $groupNames) {
            $t->owner_name = $t->owner_type === 'customer'
                ? ($customerNames[$t->owner_id] ?? null)
                : ($groupNames[$t->owner_id] ?? null);

            return $t;
        });

        return response()->json(['data' => $page]);
    }

    /**
     * GET /api/crm/loyalty/summary
     *
     * The top-card figures for the standalone screen: total points issued
     * (confirmed earn, across every owner in scope) and how many rules are
     * currently active. Branch-scoped the same way transactions() is.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $visibleCustomerIds = $this->access->isGlobal($user)
            ? null
            : $this->access->visibleCustomers($user)->pluck('id');

        $scoped = fn () => LoyaltyTransaction::query()
            ->when($visibleCustomerIds !== null, fn ($q) => $q->where(
                fn (Builder $w) => $w
                    ->where(fn (Builder $c) => $c
                        ->where('owner_type', 'customer')
                        ->whereIn('owner_id', $visibleCustomerIds))
                    ->orWhere('owner_type', 'group')
            ));

        $totalPointsIssued = (clone $scoped())
            ->where('status', 'confirmed')
            ->whereIn('type', ['earn', 'referral_bonus'])
            ->sum('points');

        $totalTransactions = (clone $scoped())->count();

        return response()->json(['data' => [
            'total_points_issued' => (float) $totalPointsIssued,
            'total_transactions' => $totalTransactions,
            'active_rules_count' => LoyaltyRule::where('is_active', true)->count(),
        ]]);
    }

    /**
     * POST /api/crm/loyalty/adjustments
     *
     * The only write path onto loyalty_transactions outside the engine
     * itself. notes is required — an unexplained balance change is worse
     * than a blocked one, the same reasoning behind resolution_notes on a
     * resolved complaint, except here it is the only field carrying that
     * reasoning at all, so it cannot be conditional the way that one is.
     */
    public function adjust(Request $request): JsonResponse
    {
        $data = $request->validate([
            'owner_type' => ['required', Rule::in(['customer', 'group'])],
            'owner_id' => ['required', 'integer'],
            'points' => ['required', 'numeric', 'not_in:0'],
            'notes' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        if ($data['owner_type'] === 'customer') {
            $customer = Customer::withoutGlobalScopes()->findOrFail($data['owner_id']);
            $this->access->authorize($request->user(), $customer);
        } else {
            CustomerGroup::findOrFail($data['owner_id']);
        }

        $txn = LoyaltyTransaction::create([
            'owner_type' => $data['owner_type'],
            'owner_id' => $data['owner_id'],
            'type' => 'manual_adjustment',
            'points' => $data['points'],
            'status' => 'confirmed',
            'notes' => $data['notes'],
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $txn->load('creator:id,name')], 201);
    }

    /**
     * Balance is this table's signed sum for an owner, never a stored
     * column — see LoyaltyTransaction's own docblock. total_redeemed reads
     * as a positive figure by negating the (assumed-negative) sum of redeem
     * rows; redemption is out of scope for this phase, so this is currently
     * always zero, but the sign convention is fixed now rather than guessed
     * at whenever redemption ships.
     */
    private function ownerSummary(string $ownerType, int $ownerId): array
    {
        $confirmed = fn () => LoyaltyTransaction::where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('status', 'confirmed');

        $balance = (clone $confirmed())->sum('points');
        $totalEarned = (clone $confirmed())->whereIn('type', ['earn', 'referral_bonus'])->sum('points');
        $totalRedeemed = (clone $confirmed())->where('type', 'redeem')->sum('points');

        return [
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'balance' => (float) $balance,
            'total_earned' => (float) $totalEarned,
            'total_redeemed' => (float) abs($totalRedeemed),
        ];
    }

    private function ownerTransactions(Request $request, string $ownerType, int $ownerId)
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(self::TYPES)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return LoyaltyTransaction::where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->with(['rule:id,name', 'creator:id,name', 'order:id,order_number'])
            ->latest('created_at')
            ->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 100));
    }
}
