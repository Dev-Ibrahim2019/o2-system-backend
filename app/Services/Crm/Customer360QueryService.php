<?php

namespace App\Services\Crm;

use App\Models\Customer;
use App\Models\CustomerComplaint;
use App\Models\User;
use App\Services\Accounting\CustomerAccountingService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class Customer360QueryService
{
    public function __construct(
        private readonly CrmCustomerAccessService $access,
        private readonly CustomerAccountingService $accounting,
        private readonly \App\Services\Customers\CustomerFinancialProfileService $financialProfiles,
    ) {}

    // Maps a public "sort" filter value to the actual query-selectable
    // column/aggregate alias it orders by. total_purchases/last_order_at
    // aren't real columns — they're the aliases withSum()/withMax() below
    // produce, so sorting by them still costs zero extra queries.
    private const SORT_COLUMNS = [
        'name' => 'name',
        'created_at' => 'created_at',
        'status' => 'status',
        'code' => 'code',
        'orders_count' => 'orders_count',
        'total_purchases' => 'orders_sum_total',
        'last_order_at' => 'orders_max_created_at',
    ];

    /**
     * The four statuses that mean "still open" for a complaint. Extracted so
     * the count aggregate and the has_complaints filter below can never drift
     * apart (they used to repeat the same list).
     */
    private const OPEN_COMPLAINT_STATUSES = [
        CustomerComplaint::STATUS_NEW,
        CustomerComplaint::STATUS_OPEN,
        CustomerComplaint::STATUS_IN_PROGRESS,
        CustomerComplaint::STATUS_WAITING_CUSTOMER,
    ];

    public function directory(User $user, array $filters): LengthAwarePaginator
    {
        $sortKey = array_key_exists($filters['sort'] ?? null, self::SORT_COLUMNS) ? $filters['sort'] : 'created_at';
        $sortColumn = self::SORT_COLUMNS[$sortKey];
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $search = trim((string) ($filters['search'] ?? ''));

        return $this->access->visibleCustomers($user)
            ->with([
                'branch:id,name',
                'primaryPhone:id,customer_id,phone,normalized_phone',
                // Feeds the directory's "مناسبة قادمة" column. Loaded rather
                // than computed in SQL because "next" depends on
                // repeats_annually (an annual occasion rolls to next year),
                // which is display logic, not a stored fact — same reasoning
                // CrmOrdersQueryService::delayed() documents for elapsed time.
                'occasions' => fn ($q) => $q
                    ->where('is_active', true)
                    // occasionable_* must be selected or the morph relation
                    // cannot be resolved from the loaded rows.
                    ->select('id', 'occasionable_type', 'occasionable_id', 'occasion_type', 'title', 'date', 'repeats_annually'),
            ])
            ->withCount([
                'orders',
                'complaints as open_complaints_count' => fn ($q) => $q->whereIn('status', self::OPEN_COMPLAINT_STATUSES),
            ])
            ->withMax('orders', 'created_at')
            ->withSum('orders', 'total')
            // Mockup filters "لديه مشكلة" / "لديه مناسبة" / "مناسبة" — all
            // answered from customer_complaints and customer_occasions, which
            // already exist. No new column, no new table.
            ->when(
                array_key_exists('has_complaints', $filters) && $filters['has_complaints'] !== null,
                fn ($q) => $filters['has_complaints']
                    ? $q->whereHas('complaints', fn ($c) => $c->whereIn('status', self::OPEN_COMPLAINT_STATUSES))
                    : $q->whereDoesntHave('complaints', fn ($c) => $c->whereIn('status', self::OPEN_COMPLAINT_STATUSES))
            )
            ->when(
                array_key_exists('has_occasion', $filters) && $filters['has_occasion'] !== null,
                fn ($q) => $filters['has_occasion']
                    ? $q->whereHas('occasions', fn ($o) => $o->where('is_active', true))
                    : $q->whereDoesntHave('occasions', fn ($o) => $o->where('is_active', true))
            )
            ->when(
                $filters['occasion_type'] ?? null,
                fn ($q, $type) => $q->whereHas('occasions', fn ($o) => $o->where('is_active', true)->where('occasion_type', $type))
            )
            ->when($search !== '', function ($query) use ($search) {
                // The stored-phones clause is added only when the term
                // actually contains digits.
                //
                // It used to be unconditional: for a name search preg_replace
                // leaves $digits empty, ltrim('', '0') is still empty, and the
                // pattern collapses to LIKE '%%' — which every phone row
                // matches. That OR branch then dragged in every customer who
                // has a phone on file, so searching a name (or any text at
                // all, including nonsense) returned the entire directory
                // instead of filtering it.
                $digits = ltrim((string) preg_replace('/\D+/', '', $search), '0');

                $query->where(function ($q) use ($search, $digits) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('mobile', 'like', "%{$search}%");

                    if ($digits !== '') {
                        $q->orWhereHas('phones', fn ($phones) => $phones
                            ->where('normalized_phone', 'like', "%{$digits}%"));
                    }
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['engagement_status'] ?? null, fn ($q, $tag) => $q->where('engagement_status', $tag))
            ->when($filters['source'] ?? null, fn ($q, $source) => $q->where('source', $source))
            ->when($filters['gender'] ?? null, fn ($q, $gender) => $q->where('gender', $gender))
            ->when(
                ($filters['branch_id'] ?? null) && $this->access->isGlobal($user),
                fn ($q) => $q->where('branch_id', $filters['branch_id'])
            )
            ->orderBy($sortColumn, $direction)
            ->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 100));
    }

    /**
     * The customer's nearest upcoming occasion, or null when they have none
     * still ahead of them. Reads only the `occasions` relation loaded by
     * directory() above, so it costs no extra query per row.
     *
     * An occasion with repeats_annually rolls forward to its next anniversary;
     * a one-off occasion whose date has already passed is not "upcoming" and
     * is skipped rather than reported as overdue.
     */
    public function nextOccasion(Customer $customer): ?array
    {
        if (! $customer->relationLoaded('occasions')) {
            return null;
        }

        $today = now()->startOfDay();
        $best = null;

        foreach ($customer->occasions as $occasion) {
            if (! $occasion->date) {
                continue;
            }

            // Delegates to the model's nextOccurrence(), the one place that
            // knows how an annual occasion rolls forward. This method used to
            // carry its own copy of that arithmetic while
            // CallCenterService::getOccasionsByRange() carried a different
            // one — which is how the two came to disagree.
            $next = $occasion->nextOccurrence($today);

            if ($next === null) {
                continue;
            }

            if ($best === null || $next->lt($best['at'])) {
                $best = ['at' => $next, 'occasion' => $occasion];
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'type' => $best['occasion']->occasion_type,
            'title' => $best['occasion']->title,
            'date' => $best['at']->toDateString(),
            'days_until' => (int) $today->diffInDays($best['at'], false),
        ];
    }

    public function profile(User $user, Customer $customer): array
    {
        $this->access->authorize($user, $customer);
        $customer->load([
            'branch:id,name',
            // The customer form's group picker reads identity.group_id, but
            // profile() never returned it — so editing a customer always
            // showed "no group" no matter what customers.group_id held.
            'group:id,name,group_type',
            'phones:id,customer_id,phone,normalized_phone,type,is_primary,is_verified',
            'address',
            // Lifted out of Employee's global BranchScope for the same reason
            // ComplaintController's assignedTo load is: a salesperson at
            // another branch is a legitimate assignment, and leaving the
            // scope on silently resolved the name to null even though
            // salesperson_id held a valid id.
            'salesperson' => fn ($q) => $q->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->select('id', 'name'),
            // Birthday is modeled as a CustomerOccasion (occasion_type='birthday'),
            // not a customers.birth_date column — see CustomerIdentityService::syncBirthdayOccasion().
            'occasions' => fn ($q) => $q->where('occasion_type', 'birthday')->where('is_active', true),
            // Work address is a CustomerAddress row labeled
            // CustomerIdentityService::WORK_ADDRESS_LABEL, not a customers.work_address column.
            'addresses' => fn ($q) => $q->where('label', \App\Services\CustomerIdentityService::WORK_ADDRESS_LABEL)->where('is_active', true),
        ])->loadCount([
            'orders',
            'orders as completed_orders_count' => fn ($q) => $q->whereIn('status', ['paid', 'served']),
            'complaints as open_complaints_count' => fn ($q) => $q->open(),
        ]);

        $orderStats = $customer->orders()
            ->selectRaw('AVG(total) as average_order_value, SUM(total) as total_purchases, MAX(created_at) as last_order_at')
            ->first();

        $birthdayOccasion = $customer->occasions->first();
        $workAddress = $customer->addresses->first();

        return [
            'id' => $customer->id,
            'identity' => [
                'name' => $customer->name,
                'name_en' => $customer->name_en,
                'title' => $customer->title,
                'gender' => $customer->gender,
                'code' => $customer->code,
                'status' => $customer->status,
                'engagement_status' => $customer->engagement_status,
                'group_id' => $customer->group_id,
                'group' => $customer->group,
                'primary_phone' => $customer->primaryPhone?->phone ?? $customer->phone ?? $customer->mobile,
                'phones' => $customer->phones,
                'email' => $customer->email,
                'branch' => $customer->branch,
                'salesperson_id' => $customer->salesperson_id,
                // Named salesperson, not assignedEmployee/salesperson_name: the
                // relation is already called salesperson() on the model, and a
                // loaded relation serializes under its own snake_case name
                // regardless of what key we assign it to here — same collision
                // class documented on ComplaintController's assignedTo. Keeping
                // the same name on both sides avoids a second implicit key.
                'salesperson' => $customer->salesperson,
                'default_address' => $customer->address,
                // customers.city / customers.country — genuinely persisted by
                // CustomerIdentityService::create()/update() (both are plain
                // mass-assignment, both columns are fillable) but never
                // returned here, so the edit form always reloaded them blank
                // regardless of what was actually saved. Same class of gap as
                // name_en/salesperson_id above, just silent instead of wrong:
                // there was no value at all to read, not a stale one.
                'city' => $customer->city,
                'country' => $customer->country,
                'loyalty_points' => $customer->loyalty_points ?? null,
                'source' => $customer->source,
                'created_at' => $customer->created_at?->toIso8601String(),
                'birth_date' => $birthdayOccasion?->date?->toDateString(),
                'work_address' => $workAddress ? [
                    'id' => $workAddress->id,
                    'label' => $workAddress->label,
                    'city' => $workAddress->city,
                    'area' => $workAddress->area,
                    'district' => $workAddress->district,
                    'street' => $workAddress->street,
                    'landmark' => $workAddress->landmark,
                    'building_no' => $workAddress->building_no,
                    'floor' => $workAddress->floor,
                    'apartment' => $workAddress->apartment,
                    'phone' => $workAddress->phone,
                ] : null,
            ],
            'summary' => [
                'orders_count' => $customer->orders_count,
                'completed_orders_count' => $customer->completed_orders_count,
                'average_order_value' => round((float) ($orderStats?->average_order_value ?? 0), 3),
                'total_purchases' => round((float) ($orderStats?->total_purchases ?? 0), 3),
                'last_order_at' => $orderStats?->last_order_at,
                'open_complaints_count' => $customer->open_complaints_count,
            ],
            'permissions' => [
                'can_edit' => $user->can('crm.edit-customers'),
                'can_view_financial' => $user->can('crm.view-customer-financial'),
                'can_view_sensitive_notes' => $user->can('crm.view-sensitive-notes'),
            ],
        ];
    }

    public function financial(User $user, Customer $customer): array
    {
        $this->access->authorize($user, $customer);
        abort_unless($user->can('crm.view-customer-financial'), 403);

        $balance = $this->accounting->getBalance($customer);
        $aging = $this->accounting->getAging($customer);

        // Loaded through the gate, which re-checks the permission the
        // abort_unless() above already enforced — belt and braces, and it is
        // what makes this a read of customer_financial_profiles rather than of
        // columns that no longer exist on `customers`.
        $profile = $this->financialProfiles->getOrFail($user, $customer);
        $creditLimit = (float) $profile->credit_limit;

        return [
            'balance' => $balance,
            'credit_limit' => $creditLimit,
            'available_credit' => max(0, $creditLimit - $balance),
            'payment_terms' => $profile->payment_terms,
            'credit_days' => $profile->credit_days,
            'aging' => $aging,
            'legacy_source' => false,
        ];
    }
}
