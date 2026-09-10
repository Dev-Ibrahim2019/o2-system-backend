<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerComplaint;
use App\Models\CustomerFamilyMember;
use App\Models\CustomerGroup;
use Illuminate\Database\Eloquent\Model;
use App\Models\CustomerNote;
use App\Models\CustomerOccasion;
use App\Models\Order;
use App\Models\OrderFeedback;
use App\Services\Accounting\CustomerAccountingService;
use App\Services\CallCenter\CallCenterService;
use App\Services\Crm\CrmCustomerAccessService;
use App\Services\Crm\CrmOrdersQueryService;
use App\Services\Crm\Customer360QueryService;
use App\Services\CustomerIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CrmController extends Controller
{
    public function __construct(
        private readonly Customer360QueryService $profiles,
        private readonly CrmCustomerAccessService $access,
        private readonly CustomerAccountingService $accounting,
        private readonly CustomerIdentityService $customerIdentity,
        // Favorites and order-details logic already exists here for Call
        // Center — reused as-is, not duplicated, for the CRM-scoped
        // equivalents below (favorites(), orderDetails()).
        private readonly CallCenterService $callCenter,
        private readonly CrmOrdersQueryService $orders,
    ) {}

    /**
     * POST /api/crm/customers
     *
     * CRM's own customer-creation path. Deliberately does NOT go through
     * CustomerFinancialController — it only accepts CRM/identity fields and
     * never applies financial defaults (currency, risk_level, payment_terms,
     * credit_days). See the CRM Customer Creation root-cause report for why
     * this endpoint exists.
     */
    private const GENDER_VALUES = ['male', 'female'];

    // customer_notes.type/importance — القيم الفعلية الموثّقة كتعليق على
    // العمودين في database/migrations/2026_07_10_000002_create_customer_notes_table.php
    // (لا يوجد enum على مستوى الـ DB، القائمة هنا تُطبّق القيم على مستوى التطبيق فقط،
    // ولا تخترع قيماً جديدة).
    private const NOTE_TYPE_VALUES = ['general', 'delivery', 'warning', 'preference', 'service', 'sensitive'];
    private const NOTE_IMPORTANCE_VALUES = ['low', 'normal', 'high', 'urgent'];

    /** Mirrors the customer_occasions enums (2027_01_18_000001). */
    private const OCCASION_TYPE_VALUES = [
        'birthday', 'anniversary', 'graduation', 'company_founding', 'contract_renewal', 'other',
    ];
    private const CONTACT_METHOD_VALUES = ['call', 'sms', 'email', 'whatsapp'];

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'           => ['required', 'string', 'max:255'],
            'name_en'        => ['nullable', 'string', 'max:255'],
            'title'          => ['nullable', 'string', 'max:32'],
            'gender'         => ['nullable', 'string', 'in:' . implode(',', self::GENDER_VALUES)],
            'phone'          => ['nullable', 'string', 'max:30'],
            'mobile'         => ['nullable', 'string', 'max:30'],
            'email'          => ['nullable', 'email', 'max:255'],
            'address'        => ['nullable', 'string', 'max:500'],
            'city'           => ['nullable', 'string', 'max:100'],
            'country'        => ['nullable', 'string', 'max:100'],
            // Business classification moved to customer_groups.group_type; a customer
            // now carries only its engagement tag plus an optional group.
            'engagement_status' => ['nullable', 'string', 'in:regular,important,vip,new,inactive,follow_up,complaints'],
            'group_id'       => ['nullable', 'integer', 'exists:customer_groups,id'],
            'source'         => ['nullable', 'string', 'in:' . implode(',', self::CUSTOMER_SOURCE_VALUES)],
            'status'         => ['nullable', 'string', 'in:active,inactive,blocked'],
            'notes'          => ['nullable', 'string', 'max:1000'],
            'branch_id'      => ['nullable', 'integer', 'exists:branches,id'],
            'salesperson_id' => ['nullable', 'integer', 'exists:employees,id'],
            'birth_date'     => ['nullable', 'date'],
            'work_address'   => ['nullable', 'array'],
            'work_address.city' => ['nullable', 'string', 'max:100'],
            'work_address.area' => ['nullable', 'string', 'max:100'],
            'work_address.district' => ['nullable', 'string', 'max:100'],
            'work_address.street' => ['nullable', 'string', 'max:255'],
            'work_address.landmark' => ['nullable', 'string', 'max:255'],
            'work_address.building_no' => ['nullable', 'string', 'max:50'],
            'work_address.floor' => ['nullable', 'string', 'max:20'],
            'work_address.apartment' => ['nullable', 'string', 'max:20'],
            'work_address.phone' => ['nullable', 'string', 'max:30'],
        ]);

        // Write scope must match the read scope. CrmCustomerAccessService only
        // shows a branch-scoped user customers whose branch_id equals their own,
        // so a customer created with a null (or foreign) branch_id would be
        // invisible — and 403 — to the very user who just created it. Mirrors
        // how CallCenterController derives its branch: the request's branch_id
        // is honoured for global users only.
        $data['branch_id'] = $this->access->isGlobal($request->user())
            ? ($data['branch_id'] ?? null)
            : $request->user()->branch_id;

        // The wizard never exposes a source picker (deliberately — one more
        // decision the operator creating a customer doesn't need to make),
        // so every request through here leaves it unset. Stamped here rather
        // than left null, the same way CustomerComplaint::CHANNEL_CRM is
        // stated by this same controller instead of trusted to the payload.
        $data['source'] = $data['source'] ?? Customer::SOURCE_CRM;

        $birthDate = $data['birth_date'] ?? null;
        $workAddress = $data['work_address'] ?? null;
        unset($data['birth_date'], $data['work_address']);

        // Backend-enforced: CRM can never create a financial customer,
        // regardless of what the client sends (the validation rules above
        // don't even accept a customer_type field from the request).
        $customer = $this->customerIdentity->create($data, Customer::TYPE_OPERATIONAL);

        if ($birthDate) {
            $this->customerIdentity->syncBirthdayOccasion($customer, $birthDate, $request->user()->id);
        }
        if ($workAddress) {
            $this->customerIdentity->syncWorkAddress($customer, $workAddress);
        }

        return response()->json(['data' => $customer], 201);
    }

    /**
     * PUT /api/crm/customers/{customer}
     *
     * CRM's own update path — mirrors store(): identity fields only, plus
     * the birthday-occasion and work-address sync that store() also does.
     * Reuses CustomerIdentityService::update() (already domain-neutral,
     * already used elsewhere) rather than duplicating field-assignment logic.
     */
    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);
        abort_unless($request->user()->can('crm.edit-customers'), 403);

        $data = $request->validate([
            'name'           => ['required', 'string', 'max:255'],
            'name_en'        => ['nullable', 'string', 'max:255'],
            'title'          => ['nullable', 'string', 'max:32'],
            'gender'         => ['nullable', 'string', 'in:' . implode(',', self::GENDER_VALUES)],
            'phone'          => ['nullable', 'string', 'max:30'],
            'mobile'         => ['nullable', 'string', 'max:30'],
            'email'          => ['nullable', 'email', 'max:255'],
            'address'        => ['nullable', 'string', 'max:500'],
            'city'           => ['nullable', 'string', 'max:100'],
            'country'        => ['nullable', 'string', 'max:100'],
            // Business classification moved to customer_groups.group_type; a customer
            // now carries only its engagement tag plus an optional group.
            'engagement_status' => ['nullable', 'string', 'in:regular,important,vip,new,inactive,follow_up,complaints'],
            'group_id'       => ['nullable', 'integer', 'exists:customer_groups,id'],
            'source'         => ['nullable', 'string', 'in:' . implode(',', self::CUSTOMER_SOURCE_VALUES)],
            'status'         => ['nullable', 'string', 'in:active,inactive,blocked'],
            'notes'          => ['nullable', 'string', 'max:1000'],
            'branch_id'      => ['nullable', 'integer', 'exists:branches,id'],
            'salesperson_id' => ['nullable', 'integer', 'exists:employees,id'],
            'birth_date'     => ['nullable', 'date'],
            'work_address'   => ['nullable', 'array'],
            'work_address.city' => ['nullable', 'string', 'max:100'],
            'work_address.area' => ['nullable', 'string', 'max:100'],
            'work_address.district' => ['nullable', 'string', 'max:100'],
            'work_address.street' => ['nullable', 'string', 'max:255'],
            'work_address.landmark' => ['nullable', 'string', 'max:255'],
            'work_address.building_no' => ['nullable', 'string', 'max:50'],
            'work_address.floor' => ['nullable', 'string', 'max:20'],
            'work_address.apartment' => ['nullable', 'string', 'max:20'],
            'work_address.phone' => ['nullable', 'string', 'max:30'],
        ]);

        // birth_date/work_address are always present as keys when the form
        // submits them (even as null, to signal "remove") — but must not be
        // treated as Customer columns.
        // Same write-scope rule as store(): a branch-scoped user must not be
        // able to move a customer into a branch they cannot read back.
        $data['branch_id'] = $this->access->isGlobal($request->user())
            ? ($data['branch_id'] ?? null)
            : $request->user()->branch_id;

        $hasBirthDateKey = $request->has('birth_date');
        $hasWorkAddressKey = $request->has('work_address');
        $birthDate = $data['birth_date'] ?? null;
        $workAddress = $data['work_address'] ?? null;
        unset($data['birth_date'], $data['work_address']);

        $customer = $this->customerIdentity->update($customer, $data);

        if ($hasBirthDateKey) {
            $this->customerIdentity->syncBirthdayOccasion($customer, $birthDate, $request->user()->id);
        }
        if ($hasWorkAddressKey) {
            $this->customerIdentity->syncWorkAddress($customer, $workAddress);
        }

        return response()->json(['data' => $customer]);
    }

    private const OCCASION_LABELS = [
        'birthday' => 'عيد ميلاد',
        'anniversary' => 'ذكرى سنوية',
        'wedding' => 'زواج',
        'graduation' => 'تخرج',
        'other' => 'أخرى',
    ];

    // Customer Source — which channel this customer first registered
    // through. Deliberately NOT the same concept as orders.source (Order
    // Source, which channel a specific order was created through); a
    // customer with source=website can freely have orders with
    // source=pos or source=call_center — that's expected, not a conflict.
    // "walk_in" replaces the old "hall" value (no customer ever had that
    // value — confirmed via direct query before this change; zero data migration needed).
    public const CUSTOMER_SOURCE_VALUES = ['website', 'fawri', 'families', 'call_center', 'walk_in'];
    private const SOURCE_LABELS = [
        'website' => 'الموقع الإلكتروني',
        'fawri' => 'كاشير فوري',
        'families' => 'كاشير عائلات',
        'call_center' => 'كاشير كول سنتر',
        'walk_in' => 'حضور مباشر',
    ];

    public function dashboard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $user = $request->user();
        $baseCustomers = $this->access->visibleCustomers($user)
            ->when(
                ($validated['branch_id'] ?? null) && $this->access->isGlobal($user),
                fn ($q) => $q->where('branch_id', $validated['branch_id'])
            );

        $from = $validated['date_from'] ?? now()->startOfMonth()->toDateString();
        $to = $validated['date_to'] ?? now()->toDateString();
        $customerIds = (clone $baseCustomers)->pluck('id');

        $customersCount = $customerIds->count();
        $activeCount = (clone $baseCustomers)->where('status', 'active')->count();
        $newInPeriod = (clone $baseCustomers)->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->count();
        $openComplaints = CustomerComplaint::query()->whereIn('customer_id', $customerIds)->open()->count();
        $ordersCount = Order::whereIn('customer_id', $customerIds)->count();

        // Month-over-month growth: real counts compared against the same
        // query 30 days ago — no fabricated percentages.
        $thirtyDaysAgo = now()->subDays(30);
        $customersCount30dAgo = (clone $baseCustomers)->where('created_at', '<', $thirtyDaysAgo)->count();
        $activeCount30dAgo = (clone $baseCustomers)->where('status', 'active')->where('created_at', '<', $thirtyDaysAgo)->count();
        $openComplaints30dAgo = CustomerComplaint::query()
            ->whereIn('customer_id', $customerIds)
            ->where('created_at', '<', $thirtyDaysAgo)
            ->open()->count();

        $trendPct = fn (int $now, int $before): ?float => $before > 0
            ? round((($now - $before) / $before) * 100, 1)
            : null;

        // Last 6 months: new customers vs. customers who placed >=1 order that month.
        $months = collect(range(5, 0))->map(fn ($i) => now()->subMonths($i));
        $monthlyNewCustomers = $months->map(fn ($month) => [
            'month' => $month->translatedFormat('M'),
            'count' => (clone $baseCustomers)->whereBetween('created_at', [
                $month->copy()->startOfMonth(), $month->copy()->endOfMonth(),
            ])->count(),
        ])->values();
        $monthlyActiveCustomers = $months->map(fn ($month) => [
            'month' => $month->translatedFormat('M'),
            'count' => Order::whereIn('customer_id', $customerIds)
                ->whereBetween('created_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
                ->distinct()->count('customer_id'),
        ])->values();

        // Always return every known occasion type, zero-filled when unused —
        // the dashboard's donut/legend structure is meant to be permanently
        // visible; only the numbers behind it grow as real occasions get
        // recorded. Never fabricates a percentage for a type that isn't there.
        $occasionCounts = CustomerOccasion::query()
            // Customer-owned only: the dashboard counts what belongs to the
            // customers in scope, not group-level occasions.
            ->where('occasionable_type', Customer::class)
            ->whereIn('occasionable_id', $customerIds)
            ->where('is_active', true)
            ->selectRaw('occasion_type, count(*) as total')
            ->groupBy('occasion_type')
            ->pluck('total', 'occasion_type');
        $occasionTotal = $occasionCounts->sum();
        $occasionDistribution = collect(self::OCCASION_LABELS)
            ->map(fn ($label, $type) => [
                'type' => $type,
                'label' => $label,
                'count' => (int) ($occasionCounts[$type] ?? 0),
                'percent' => $occasionTotal > 0 ? round((($occasionCounts[$type] ?? 0) / $occasionTotal) * 100, 1) : 0,
            ])
            ->sortByDesc('count')
            ->values();

        // Same principle for sources — all 5 known channels always listed.
        $sourceCounts = (clone $baseCustomers)->whereNotNull('source')
            ->selectRaw('source, count(*) as total')->groupBy('source')->pluck('total', 'source');
        $sourceTotal = $sourceCounts->sum();
        $customerSources = collect(self::SOURCE_LABELS)
            ->map(fn ($label, $source) => [
                'source' => $source,
                'label' => $label,
                'count' => (int) ($sourceCounts[$source] ?? 0),
                'percent' => $sourceTotal > 0 ? round((($sourceCounts[$source] ?? 0) / $sourceTotal) * 100, 1) : 0,
            ])
            ->sortByDesc('count')
            ->values();

        $topCustomersByLoyalty = (clone $baseCustomers)
            ->where('loyalty_points', '>', 0)
            ->orderByDesc('loyalty_points')
            ->limit(5)
            ->get(['id', 'name', 'code', 'loyalty_points']);

        $recentCustomers = (clone $baseCustomers)
            ->with('branch:id,name')
            ->latest()
            ->limit(5)
            ->get(['id', 'code', 'name', 'phone', 'mobile', 'email', 'engagement_status', 'status', 'loyalty_points', 'branch_id', 'created_at']);

        // Each recent-customer row shows their nearest/most recent occasion
        // (birthday, anniversary, ...) — real per-customer data, not their
        // business category, matching what the dashboard table is meant to show.
        $latestOccasionByCustomer = CustomerOccasion::query()
            ->where('occasionable_type', Customer::class)
            ->whereIn('occasionable_id', $recentCustomers->pluck('id'))
            ->where('is_active', true)
            ->orderByDesc('date')
            ->get(['occasionable_id', 'occasion_type', 'date'])
            ->unique('occasionable_id')
            ->keyBy('occasionable_id');
        $recentCustomers = $recentCustomers->map(function ($customer) use ($latestOccasionByCustomer) {
            $occasion = $latestOccasionByCustomer->get($customer->id);
            $customer->setAttribute('occasion_type', $occasion?->occasion_type);
            $customer->setAttribute('occasion_label', $occasion ? (self::OCCASION_LABELS[$occasion->occasion_type] ?? $occasion->occasion_type) : null);

            return $customer;
        });

        $branches = $this->access->isGlobal($user) ? Branch::query()->select('id', 'name')->orderBy('name')->get() : [];

        $data = [
            'filters' => ['branch_id' => $validated['branch_id'] ?? null, 'date_from' => $from, 'date_to' => $to],
            'customers_count' => $customersCount,
            'active_customers_count' => $activeCount,
            'new_customers_count' => $newInPeriod,
            'open_complaints_count' => $openComplaints,
            'orders_count' => $ordersCount,
            'loyalty_points_total' => (int) (clone $baseCustomers)->sum('loyalty_points'),
            'trends' => [
                'customers_count' => $trendPct($customersCount, $customersCount30dAgo),
                'active_customers_count' => $trendPct($activeCount, $activeCount30dAgo),
                'open_complaints_count' => $trendPct($openComplaints, $openComplaints30dAgo),
            ],
            'monthly_new_customers' => $monthlyNewCustomers,
            'monthly_active_customers' => $monthlyActiveCustomers,
            'occasion_distribution' => $occasionDistribution,
            'customer_sources' => $customerSources,
            'top_customers_by_loyalty' => $topCustomersByLoyalty,
            'recent_customers' => $recentCustomers,
            'branches' => $branches,
            'financial' => null,
            'permissions' => [
                'can_view_financial' => $user->can('crm.view-customer-financial'),
            ],
        ];

        if ($user->can('crm.view-customer-financial')) {
            $data['financial'] = ['status' => 'available_in_customer_profile'];
        }

        return response()->json(['data' => $data]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive,blocked'],
            'engagement_status' => ['nullable', 'string', 'max:50'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'source' => ['nullable', 'string', 'in:' . implode(',', self::CUSTOMER_SOURCE_VALUES)],
            'gender' => ['nullable', 'string', 'in:' . implode(',', self::GENDER_VALUES)],
            // Directory filters from the approved mockup — answered from the
            // existing customer_complaints / customer_occasions tables.
            'has_complaints' => ['nullable', 'boolean'],
            'has_occasion' => ['nullable', 'boolean'],
            'occasion_type' => ['nullable', 'string', 'in:' . implode(',', array_keys(self::OCCASION_LABELS))],
            'sort' => ['nullable', 'string'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // "absent" and "explicitly false" mean different things to the query
        // (no filter vs. only-customers-without), so normalise to true/false/null
        // instead of letting an omitted key read as false.
        foreach (['has_complaints', 'has_occasion'] as $flag) {
            $filters[$flag] = $request->exists($flag) && $request->input($flag) !== ''
                ? $request->boolean($flag)
                : null;
        }

        $page = $this->profiles->directory($request->user(), $filters);
        $canFinancial = $request->user()->can('crm.view-customer-financial');
        $page->getCollection()->transform(function (Customer $customer) use ($canFinancial) {
            $item = [
                'id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
                'title' => $customer->title,
                'gender' => $customer->gender,
                'status' => $customer->status,
                'engagement_status' => $customer->engagement_status,
                'phone' => $customer->phone,
                'mobile' => $customer->mobile,
                'primary_phone' => $customer->primaryPhone?->phone ?? $customer->phone ?? $customer->mobile,
                'email' => $customer->email,
                'city' => $customer->city,
                'source' => $customer->source,
                'branch' => $customer->branch,
                'orders_count' => $customer->orders_count,
                'total_purchases' => (float) ($customer->orders_sum_total ?? 0),
                'open_complaints_count' => $customer->open_complaints_count,
                'loyalty_points' => $customer->loyalty_points,
                'last_order_at' => $customer->orders_max_created_at,
                'created_at' => $customer->created_at?->toIso8601String(),
                'next_occasion' => $this->withOccasionLabel($this->profiles->nextOccasion($customer)),
            ];
            if ($canFinancial) {
                $item['balance'] = $this->accounting->getBalance($customer);
            }
            return $item;
        });

        return response()->json(['data' => $page]);
    }

    /**
     * Adds the Arabic label for an occasion type to the payload
     * Customer360QueryService::nextOccasion() returns, reusing the same
     * OCCASION_LABELS map the dashboard's occasion distribution already uses
     * so a type is never labelled two different ways.
     */
    private function withOccasionLabel(?array $occasion): ?array
    {
        if ($occasion === null) {
            return null;
        }

        return $occasion + [
            'label' => self::OCCASION_LABELS[$occasion['type']] ?? $occasion['title'] ?? $occasion['type'],
        ];
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        return response()->json(['data' => $this->profiles->profile($request->user(), $customer)]);
    }

    public function overview(Request $request, Customer $customer): JsonResponse
    {
        return $this->show($request, $customer);
    }

    /**
     * The customer's orders, shaped for the mockup's "طلبات العميل" table.
     *
     * Two columns the mockup shows are added here as derived, read-only
     * fields — both from tables that already exist, neither stored on the
     * order itself:
     *  - `rating`: the mean of order_feedback's three 1–5 scores
     *    (food_quality / service_quality / delivery_speed), null when the
     *    order was never rated. Averaged rather than picking one dimension
     *    because the mockup shows a single star rating per order.
     *  - `has_complaint`: whether any customer_complaint points at this order.
     *
     * The mockup's "طريقة الدفع" column (نقدي / بطاقة / تحويل بنكي) is NOT
     * added: an order's method lives in two unreconciled places — POS settles
     * through invoices→payments while Call Center writes payment_confirmations
     * (the same split CrmOrdersQueryService::applyPaymentFilter() documents).
     * Picking one silently would mislabel every order settled by the other, so
     * `payment_status`, which IS on the order, is returned instead and the
     * column decision is left open.
     */
    public function orders(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);

        // Both are correlated sub-selects rather than withCount()/withAvg():
        // Order has no `complaints` relation (customer_complaints.order_id is
        // a plain FK nothing had needed to traverse yet), and adding one just
        // to read a count would mean editing the Order model for a CRM-only
        // display concern. CustomerComplaint's SoftDeletes scope still applies
        // because the sub-select is built from the model, not a raw table.
        $page = $customer->orders()
            ->with('branch:id,name')
            ->select('orders.*')
            ->addSelect([
                'rating' => OrderFeedback::query()
                    ->selectRaw('ROUND((food_quality + service_quality + delivery_speed) / 3, 1)')
                    ->whereColumn('order_feedback.order_id', 'orders.id')
                    ->limit(1),
                'complaints_count' => CustomerComplaint::query()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('customer_complaints.order_id', 'orders.id'),
            ])
            ->latest()
            ->paginate(15);

        $page->getCollection()->transform(function (Order $order) {
            $order->setAttribute('rating', $order->rating === null ? null : (float) $order->rating);
            $order->setAttribute('has_complaint', (int) $order->complaints_count > 0);
            return $order;
        });

        return response()->json(['data' => $page]);
    }

    /**
     * GET /api/crm/customers/{customer}/favorites
     *
     * CRM-scoped equivalent of the Call Center favorites endpoint — reuses
     * CallCenterService::getCustomerFavorites() as-is (product name,
     * quantity, order count, amount spent, last ordered), just gated by
     * CRM's own branch-scoped authorization instead of the call-center
     * permission group.
     */
    public function favorites(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);
        $limit = (int) $request->input('limit', 20);

        return response()->json(['data' => $this->callCenter->getCustomerFavorites($customer->id, $limit)]);
    }

    /**
     * GET /api/crm/customers/{customer}/purchase-history
     *
     * Last 6 months of order totals for this customer, always returning
     * all 6 months (zero-filled where there's no purchase that month) —
     * same fixed-window pattern used by the CRM dashboard's monthly chart,
     * scoped here to a single customer instead of all customers.
     */
    public function purchaseHistory(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);

        $months = collect(range(5, 0))->map(fn ($i) => now()->subMonths($i));
        $totalsByMonth = $customer->orders()
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, SUM(total) as total")
            ->where('created_at', '>=', $months->first()->copy()->startOfMonth())
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $series = $months->map(fn ($month) => [
            'month' => $month->translatedFormat('M'),
            'amount' => round((float) ($totalsByMonth[$month->format('Y-m')] ?? 0), 3),
        ])->values();

        return response()->json(['data' => ['months' => $series]]);
    }

    public function addresses(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);
        return response()->json(['data' => $customer->addresses()->orderByDesc('is_default')->latest('last_used_at')->get()]);
    }

    /**
     * POST /api/crm/customers/{customer}/addresses
     *
     * Adds a saved address (home / work / other) to the customer. Takes the
     * same fields Call Center's storeAddress() accepts and goes through the
     * same service the wizard's work-address write uses
     * (CustomerIdentityService::createAddress, which owns the "only one
     * default" invariant). Gated on crm.edit-customers, not a new
     * address-specific permission — an address is contact data about the
     * customer, the same call the family-members routes made.
     */
    public function storeAddress(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);

        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:50'],
            'city' => ['nullable', 'string', 'max:100'],
            'area' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'street' => ['nullable', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'building_no' => ['nullable', 'string', 'max:50'],
            'floor' => ['nullable', 'string', 'max:20'],
            'apartment' => ['nullable', 'string', 'max:20'],
            'delivery_notes' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        // An address row that is only a label says nothing about where the
        // place is — and still shows up in the Call Center's delivery picker.
        // Require at least one locating line.
        $hasLocation = collect($data)
            ->except(['label', 'is_default'])
            ->contains(fn ($value) => $value !== null && $value !== '');
        if (! $hasLocation) {
            throw ValidationException::withMessages([
                'street' => 'أدخل بيانات العنوان — الشارع أو المنطقة أو المدينة على الأقل.',
            ]);
        }

        $address = $this->customerIdentity->createAddress($customer, $data);

        return response()->json(['data' => $address], 201);
    }

    /**
     * GET /api/crm/customers/{customer}/activity
     *
     * Surfaces two already-existing, already-collected data sources for
     * this customer — nothing here is synthesized:
     *  - App\Models\AuditLog (App\Observers\AuditObserver, via the
     *    Auditable trait already used on Customer): create/update/delete.
     *  - App\Models\CallTicket (customer_id already exists on this table):
     *    real call-center calls linked to this customer.
     * Both were already being written; neither was exposed to CRM before.
     */
    public function activity(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);

        $auditEvents = \App\Models\AuditLog::where('auditable_type', Customer::class)
            ->where('auditable_id', $customer->id)
            ->with('user:id,name')
            ->get()
            ->map(fn ($log) => [
                'id' => 'audit-' . $log->id,
                'event' => $log->event,
                'label' => match ($log->event) {
                    'created' => 'تم تسجيل العميل',
                    'updated' => 'تم تحديث بيانات العميل',
                    'deleted' => 'تم حذف العميل',
                    default => $log->event,
                },
                'user' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
                'timestamp' => $log->created_at?->toIso8601String(),
            ]);

        $callEvents = \App\Models\CallTicket::where('customer_id', $customer->id)
            ->with('agent:id,name')
            ->get()
            ->map(fn ($ticket) => [
                'id' => 'call-' . $ticket->id,
                'event' => 'call',
                'label' => $ticket->direction === 'outbound' ? 'مكالمة هاتفية صادرة' : 'مكالمة هاتفية واردة',
                'user' => $ticket->agent ? ['id' => $ticket->agent->id, 'name' => $ticket->agent->name] : null,
                'timestamp' => ($ticket->started_at ?? $ticket->created_at)?->toIso8601String(),
            ]);

        $events = $auditEvents->concat($callEvents)
            ->sortByDesc('timestamp')
            ->values()
            ->take(30);

        return response()->json(['data' => $events]);
    }

    public function complaints(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);
        $query = $customer->complaints()->with(['assignedTo', 'followups'])->latest();
        if (! $request->user()->can('crm.view-sensitive-notes')) {
            $query->where('is_sensitive', false);
        }
        return response()->json(['data' => $query->paginate(15)]);
    }

    /**
     * POST /api/crm/customers/{customer}/complaints
     *
     * CRM's own entry point onto the complaint logic that already exists in
     * CallCenterService — reused, not reimplemented, so both channels share
     * one lifecycle, one followup trail and one set of defaults. The only
     * difference is the permission that guards the door.
     */
    public function createComplaint(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);

        $data = $request->validate([
            'title'        => ['required', 'string', 'max:255'],
            'description'  => ['nullable', 'string'],
            'order_id'     => ['nullable', 'integer', 'exists:orders,id'],
            'invoice_id'   => ['nullable', 'integer', 'exists:invoices,id'],
            'assigned_to'  => ['nullable', 'integer', 'exists:employees,id'],
            'type'         => ['nullable', 'string', 'max:50'],
            'priority'     => ['nullable', 'in:low,normal,high,critical'],
            'severity'     => ['nullable', 'in:info,warning,critical'],
            'is_sensitive' => ['nullable', 'boolean'],
            // No permission gate: a department tag is a reporting dimension,
            // not confidential data. Whoever may file the complaint may say
            // which department it concerns.
            'department'   => ['nullable', Rule::in(CustomerComplaint::DEPARTMENTS)],
        ]);

        // Same gate the notes endpoints use: a sensitive record is only
        // creatable by someone allowed to see sensitive records.
        if ($request->boolean('is_sensitive')) {
            abort_unless($request->user()->can('crm.view-sensitive-notes'), 403, 'لا تملك صلاحية إنشاء شكوى حساسة.');
        }

        $complaint = $this->callCenter->createComplaint(
            [...$data, 'customer_id' => $customer->id],
            $request->user()->id,
            // Stated by the route, not the payload — $data above never
            // accepts a channel, so this is the only thing that can set it.
            CustomerComplaint::CHANNEL_CRM,
        );

        return response()->json([
            'data' => $complaint->load(['customer:id,name,phone', 'order:id,order_number']),
        ], 201);
    }

    /**
     * PUT /api/crm/complaints/{complaint}
     *
     * Status changes go through CallCenterService::updateComplaintStatus(),
     * which owns the lifecycle guard — an invalid transition is rejected here
     * exactly as it is on the Call Center path.
     */
    public function updateComplaint(Request $request, CustomerComplaint $complaint): JsonResponse
    {
        $this->access->authorize($request->user(), $complaint->customer);

        if ($complaint->is_sensitive) {
            abort_unless($request->user()->can('crm.view-sensitive-notes'), 403, 'لا تملك صلاحية تعديل شكوى حساسة.');
        }

        $data = $request->validate([
            'status'            => ['nullable', 'in:new,open,in_progress,waiting_customer,resolved,closed,cancelled'],
            'assigned_to'       => ['nullable', 'integer', 'exists:employees,id'],
            'priority'          => ['nullable', 'in:low,normal,high,critical'],
            'title'             => ['nullable', 'string', 'max:255'],
            'description'       => ['nullable', 'string'],
            'resolution_notes'  => ['nullable', 'string'],
            'resolution_result' => ['nullable', 'string', 'max:50'],
            'type'              => ['nullable', 'string', 'max:50'],
            // 'sometimes' rather than 'nullable': the column is NOT NULL, so
            // an explicit null must be rejected as invalid, not written.
            'is_sensitive'      => ['sometimes', 'boolean'],
            // Nullable here, unlike is_sensitive: the column is nullable, so
            // an explicit null is the legitimate way to clear a mistaken tag.
            'department'        => ['nullable', Rule::in(CustomerComplaint::DEPARTMENTS)],
        ]);

        // Same rule the notes path enforces, in both directions: classifying a
        // complaint as sensitive and declassifying one both need the clearance.
        // The check above already covers a complaint that is sensitive today;
        // this covers one that is about to become sensitive. Until now the
        // field was simply absent from the validator, so a request asking to
        // reclassify returned 200 and changed nothing — a silent no-op is the
        // worst of the three possible answers.
        if (array_key_exists('is_sensitive', $data)) {
            abort_unless(
                $request->user()->can('crm.view-sensitive-notes'),
                403,
                'لا تملك صلاحية تغيير تصنيف حساسية الشكوى.',
            );
        }

        // Resolving is the one transition that must carry an explanation:
        // "resolved" is a claim that something was done about the complaint,
        // and a resolved ticket with no record of what was done is worthless
        // the moment the customer calls back. Closing, cancelling and
        // reopening are deliberately exempt — they are administrative moves,
        // not claims.
        if (($data['status'] ?? null) === CustomerComplaint::STATUS_RESOLVED
            && trim((string) ($data['resolution_notes'] ?? $complaint->resolution_notes ?? '')) === ''
        ) {
            throw ValidationException::withMessages([
                'resolution_notes' => 'يجب تسجيل الحل قبل تحويل الشكوى إلى "محلولة".',
            ]);
        }

        if (isset($data['status'])) {
            $resolutionNotes = $data['resolution_notes'] ?? null;

            $complaint = $this->callCenter->updateComplaintStatus(
                $complaint->id,
                $data['status'],
                $request->user()->id,
                $resolutionNotes,
            );

            unset($data['status']);

            // updateComplaintStatus() only writes the note into the followup
            // trail; it never touches the column. Left as it was, a
            // resolution sent alongside a status change was recorded in the
            // history and silently lost from the field meant to hold it.
            if ($resolutionNotes !== null) {
                $data['resolution_notes'] = $resolutionNotes;
            } else {
                unset($data['resolution_notes']);
            }
        }

        if ($data !== []) {
            $complaint->update($data);
        }

        return response()->json([
            'data' => $complaint->fresh()->load(['customer:id,name,phone', 'followups']),
        ]);
    }

    public function notes(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);
        $query = $customer->notes()->with('createdBy:id,name')->latest();
        if (! $request->user()->can('crm.view-sensitive-notes')) {
            $query->where('type', '!=', 'sensitive');
        }
        return response()->json(['data' => $query->paginate(20)]);
    }

    /**
     * POST /api/crm/customers/{customer}/notes
     */
    public function createNote(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);

        $data = $request->validate([
            'content'            => ['required', 'string', 'max:2000'],
            'type'               => ['nullable', 'string', 'in:' . implode(',', self::NOTE_TYPE_VALUES)],
            'importance'         => ['nullable', 'string', 'in:' . implode(',', self::NOTE_IMPORTANCE_VALUES)],
            'show_during_order'  => ['nullable', 'boolean'],
        ]);

        // ملاحظة حساسة (sensitive) تتطلب نفس الصلاحية المستخدمة أصلاً لعرضها
        // (crm.view-sensitive-notes) — لا صلاحية جديدة مخترعة، إعادة استخدام
        // للمفهوم الموجود بالفعل بدل تكراره باسم مختلف.
        if (($data['type'] ?? null) === 'sensitive') {
            abort_unless($request->user()->can('crm.view-sensitive-notes'), 403, 'لا تملك صلاحية إنشاء ملاحظة حساسة.');
        }

        $note = $customer->notes()->create([
            ...$data,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $note->load('createdBy:id,name')], 201);
    }

    /**
     * PUT /api/crm/customers/{customer}/notes/{note}
     */
    public function updateNote(Request $request, Customer $customer, CustomerNote $note): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);
        $this->assertNoteOwnership($customer, $note);

        $data = $request->validate([
            'content'            => ['required', 'string', 'max:2000'],
            'type'               => ['nullable', 'string', 'in:' . implode(',', self::NOTE_TYPE_VALUES)],
            'importance'         => ['nullable', 'string', 'in:' . implode(',', self::NOTE_IMPORTANCE_VALUES)],
            'show_during_order'  => ['nullable', 'boolean'],
        ]);

        // بوابة الصلاحية تُطبَّق إن كانت الملاحظة حساسة حالياً أو ستصبح حساسة
        // بعد هذا التعديل — كلا الاتجاهين يتطلبان نفس الصلاحية.
        if ($note->type === 'sensitive' || ($data['type'] ?? null) === 'sensitive') {
            abort_unless($request->user()->can('crm.view-sensitive-notes'), 403, 'لا تملك صلاحية تعديل ملاحظة حساسة.');
        }

        $note->update($data);

        return response()->json(['data' => $note->fresh()->load('createdBy:id,name')]);
    }

    /**
     * DELETE /api/crm/customers/{customer}/notes/{note}
     */
    public function deleteNote(Request $request, Customer $customer, CustomerNote $note): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);
        $this->assertNoteOwnership($customer, $note);

        if ($note->type === 'sensitive') {
            abort_unless($request->user()->can('crm.view-sensitive-notes'), 403, 'لا تملك صلاحية حذف ملاحظة حساسة.');
        }

        $note->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    // نفس نمط OrderFeedbackController::assertOwnership() — يمنع الوصول إلى
    // ملاحظة عبر customer_id لا تخصه (مثال: PUT /customers/B/notes/{note
    // ينتمي فعلياً لـ Customer A}).
    private function assertNoteOwnership(Customer $customer, CustomerNote $note): void
    {
        abort_unless((int) $note->customer_id === (int) $customer->id, 404);
    }

    public function occasions(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);
        return response()->json(['data' => $customer->occasions()->where('is_active', true)->orderBy('date')->get()]);
    }


    /**
     * Refuses an occasion that is not owned by the model in the URL.
     *
     * A 404, not a 403: the two are indistinguishable to a caller probing ids,
     * which is the same non-disclosure rule the complaint endpoints follow.
     * Owner type is compared as well as id — without it, group 5's occasion
     * would be editable through customer 5's route.
     */
    private function assertOccasionBelongsTo(CustomerOccasion $occasion, Model $owner): void
    {
        abort_unless(
            $occasion->occasionable_type === $owner->getMorphClass()
                && (int) $occasion->occasionable_id === (int) $owner->getKey(),
            404,
            'المناسبة غير موجودة.'
        );
    }

    private function assertFamilyMemberBelongsTo(CustomerFamilyMember $familyMember, Customer $customer): void
    {
        abort_unless(
            (int) $familyMember->customer_id === (int) $customer->getKey(),
            404,
            'فرد العائلة غير موجود.'
        );
    }

    /**
     * GET /api/crm/groups/{group}/occasions
     *
     * Group occasions are the group's own dates — a founding anniversary, a
     * contract renewal — not the personal occasions of its members.
     *
     * No branch scoping: customer_groups carries no branch_id, so there is no
     * branch to scope by. Access rests on the crm.occasions.* permissions
     * alone, which is the same treatment CustomerGroupController@index gives
     * the group list itself.
     */
    public function groupOccasions(Request $request, CustomerGroup $group): JsonResponse
    {
        return response()->json([
            'data' => $group->occasions()->where('is_active', true)->orderBy('date')->get(),
        ]);
    }

    /** POST /api/crm/groups/{group}/occasions */
    public function createGroupOccasion(Request $request, CustomerGroup $group): JsonResponse
    {
        $data = $request->validate($this->occasionRules(required: true));

        $occasion = $this->callCenter->createOccasion($group, $data, $request->user()->id);

        return response()->json(['data' => $occasion], 201);
    }

    /** PUT /api/crm/groups/{group}/occasions/{occasion} */
    public function updateGroupOccasion(Request $request, CustomerGroup $group, CustomerOccasion $occasion): JsonResponse
    {
        $this->assertOccasionBelongsTo($occasion, $group);

        $data = $request->validate($this->occasionRules(required: false));

        return response()->json(['data' => $this->callCenter->updateOccasion($occasion->id, $data)]);
    }

    /** DELETE /api/crm/groups/{group}/occasions/{occasion} */
    public function deleteGroupOccasion(Request $request, CustomerGroup $group, CustomerOccasion $occasion): JsonResponse
    {
        $this->assertOccasionBelongsTo($occasion, $group);

        $this->callCenter->deleteOccasion($occasion->id);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * One rule set for both owner types — the fields do not differ, only
     * whether they are mandatory (create) or optional (update).
     */
    private function occasionRules(bool $required): array
    {
        $presence = $required ? 'required' : 'nullable';

        return [
            'occasion_type' => [$presence, Rule::in(self::OCCASION_TYPE_VALUES)],
            'title' => [$presence, 'string', 'max:255'],
            'date' => [$presence, 'date'],
            'repeats_annually' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'preferred_contact_method' => ['nullable', Rule::in(self::CONTACT_METHOD_VALUES)],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * POST /api/crm/customers/{customer}/occasions
     *
     * CRM's own door onto CallCenterService::createOccasion(). Until now the
     * only create path sat behind the Call Center role group, so a crm-manager
     * got a 403 on the one route that could add an occasion.
     *
     * The two enum columns are validated here as well as in the database:
     * Rule::in gives a 422 naming the field, where a bare DB rejection would
     * surface as a 500.
     */
    public function createOccasion(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);

        $data = $request->validate([
            'occasion_type' => ['required', Rule::in(self::OCCASION_TYPE_VALUES)],
            'title' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'repeats_annually' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'preferred_contact_method' => ['nullable', Rule::in(self::CONTACT_METHOD_VALUES)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $occasion = $this->callCenter->createOccasion($customer, $data, $request->user()->id);

        return response()->json(['data' => $occasion], 201);
    }

    /**
     * PUT /api/crm/customers/{customer}/occasions/{occasion}
     *
     * The occasion is addressed under its customer so the branch check has
     * something to run against, and asserted to belong to it — otherwise the
     * customer in the URL would be decorative and any occasion id would be
     * editable by anyone who can reach one customer.
     */
    public function updateOccasion(Request $request, Customer $customer, CustomerOccasion $occasion): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);
        $this->assertOccasionBelongsTo($occasion, $customer);

        $data = $request->validate([
            'occasion_type' => ['nullable', Rule::in(self::OCCASION_TYPE_VALUES)],
            'title' => ['nullable', 'string', 'max:255'],
            'date' => ['nullable', 'date'],
            'repeats_annually' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'preferred_contact_method' => ['nullable', Rule::in(self::CONTACT_METHOD_VALUES)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return response()->json(['data' => $this->callCenter->updateOccasion($occasion->id, $data)]);
    }

    /**
     * DELETE /api/crm/customers/{customer}/occasions/{occasion}
     *
     * Soft delete, as the model defines — a mistyped occasion goes away
     * without the row being destroyed.
     */
    public function deleteOccasion(Request $request, Customer $customer, CustomerOccasion $occasion): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);
        $this->assertOccasionBelongsTo($occasion, $customer);

        $this->callCenter->deleteOccasion($occasion->id);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * GET /api/crm/customers/{customer}/family-members
     *
     * Same permission as reading the customer itself — this is identity
     * data about them, not a separately-gated concern (no new permission,
     * per the feature's own spec).
     */
    public function familyMembers(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);

        return response()->json(['data' => $customer->familyMembers()->latest()->get()]);
    }

    /**
     * POST /api/crm/customers/{customer}/family-members
     *
     * birth_date is optional here (unlike occasions.date, which is
     * required): a family member is worth recording even before their
     * birthday is known, and CustomerIdentityService::createFamilyMember()
     * only creates the linked occasion once one is actually given.
     */
    public function storeFamilyMember(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'relationship' => ['required', Rule::in(CustomerFamilyMember::RELATIONSHIPS)],
            'birth_date' => ['nullable', 'date'],
        ]);

        $member = $this->customerIdentity->createFamilyMember($customer, $data, $request->user()->id);

        return response()->json(['data' => $member], 201);
    }

    public function updateFamilyMember(Request $request, Customer $customer, CustomerFamilyMember $familyMember): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);
        $this->assertFamilyMemberBelongsTo($familyMember, $customer);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'relationship' => ['nullable', Rule::in(CustomerFamilyMember::RELATIONSHIPS)],
            'birth_date' => ['nullable', 'date'],
        ]);

        return response()->json(['data' => $this->customerIdentity->updateFamilyMember($familyMember, $data)]);
    }

    /**
     * DELETE /api/crm/customers/{customer}/family-members/{familyMember}
     *
     * Soft delete, and takes the family member's birthday occasion (also
     * soft) down with it — see
     * CustomerIdentityService::deleteFamilyMember().
     */
    public function deleteFamilyMember(Request $request, Customer $customer, CustomerFamilyMember $familyMember): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);
        $this->assertFamilyMemberBelongsTo($familyMember, $customer);

        $this->customerIdentity->deleteFamilyMember($familyMember);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function financial(Request $request, Customer $customer): JsonResponse
    {
        return response()->json(['data' => $this->profiles->financial($request->user(), $customer)]);
    }

    public function statement(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);
        abort_unless($request->user()->can('crm.view-customer-statement'), 403);
        return response()->json(['data' => $this->accounting->getStatement(
            $customer,
            $request->date_from ?? now()->subYear()->toDateString(),
            $request->date_to ?? now()->toDateString(),
            $this->access->isGlobal($request->user()) ? null : $request->user()->branch_id,
        )]);
    }

    public function aging(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);
        abort_unless($request->user()->can('crm.view-customer-financial'), 403);
        return response()->json(['data' => $this->accounting->getAging($customer, $request->as_of)]);
    }

    /**
     * GET /api/crm/orders
     *
     * Read-only, cross-customer order monitoring list for CRM — per the
     * Order Domain Audit, this is a projection over the existing `orders`
     * table only (branch-scoped automatically via Order's own BranchScope),
     * never a parallel Order system. CRM cannot create/confirm/serve/pay/
     * cancel orders through this or any other CRM endpoint.
     *
     * ?active=1 filters to non-terminal orders (status not in
     * paid/served/cancelled) for the "الطلبات النشطة" view — same page,
     * different query param, not a separate implementation.
     */
    public function ordersIndex(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:50'],
            'source' => ['nullable', 'string', 'max:50'],
            'payment_status' => ['nullable', 'in:unpaid,awaiting_confirmation,processing,paid,failed,refunded'],
            'order_type' => ['nullable', 'in:dine_in,takeaway,delivery'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->orders->list($request->user(), $filters);
        $customers = $this->customersFor($page->getCollection());
        $page->getCollection()->transform(fn (Order $order) => $this->transformOrderRow($order, null, $customers));

        return response()->json(['data' => $page]);
    }

    /**
     * GET /api/crm/orders/delayed
     *
     * Active orders whose elapsed time since creation exceeds ?minutes=
     * (default 30). Elapsed time is measured from created_at only — see
     * CrmOrdersQueryService::delayed() for why order-level per-status
     * timing isn't available today. This does not write anything and does
     * not decide "whose fault" a delay is — that attribution layer does
     * not exist yet, per the Order Domain Audit.
     */
    public function ordersDelayed(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'source' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $minutes = (int) ($filters['minutes'] ?? 30);
        $page = $this->orders->delayed($request->user(), $minutes, $filters);
        $customers = $this->customersFor($page->getCollection());
        $page->getCollection()->transform(fn (Order $order) => $this->transformOrderRow($order, $minutes, $customers));

        return response()->json(['data' => $page, 'threshold_minutes' => $minutes]);
    }

    /**
     * Order has no `customer()` relation (only a `customer_id` column) —
     * batch-fetched separately here rather than adding a relation to the
     * Order model, which is out of scope for a CRM read endpoint.
     */
    private function customersFor(\Illuminate\Support\Collection $orders): \Illuminate\Support\Collection
    {
        $ids = $orders->pluck('customer_id')->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return Customer::query()->whereIn('id', $ids)->get(['id', 'name', 'code'])->keyBy('id');
    }

    private function transformOrderRow(Order $order, ?int $delayThresholdMinutes = null, ?\Illuminate\Support\Collection $customers = null): array
    {
        $elapsedMinutes = (int) $order->created_at->diffInMinutes(now());
        $customer = $order->customer_id ? $customers?->get($order->customer_id) : null;

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            // Derived, not stored — see CrmOrdersQueryService::applyPaymentFilter()
            // for why: order.status='paid' (the POS/general path) and
            // order.payment_status='paid' (the Call Center path) are the two
            // real ways "paid" is represented today. The UI needs one
            // consistent answer to "is this paid", not two raw columns that
            // disagree on which orders they cover.
            'is_paid' => $order->status === 'paid' || $order->payment_status === 'paid',
            'order_type' => $order->order_type,
            'source' => $order->source,
            'total' => (float) $order->total,
            'branch' => $order->branch ? ['id' => $order->branch->id, 'name' => $order->branch->name] : null,
            'table' => $order->diningTable ? [
                'table_number' => $order->diningTable->table_number,
                'zone' => $order->diningTable->zone?->name,
            ] : ($order->table_number ? ['table_number' => $order->table_number, 'zone' => null] : null),
            'customer' => $customer
                ? ['id' => $customer->id, 'name' => $customer->name, 'code' => $customer->code]
                : ($order->customer_name ? ['id' => null, 'name' => $order->customer_name, 'code' => null] : null),
            'customer_phone' => $order->customer_phone,
            'cashier' => $order->cashier ? ['id' => $order->cashier->id, 'name' => $order->cashier->name] : null,
            'created_at' => $order->created_at?->toIso8601String(),
            'elapsed_minutes' => $elapsedMinutes,
            'is_delayed' => $delayThresholdMinutes !== null ? $elapsedMinutes >= $delayThresholdMinutes : null,
        ];
    }

    /**
     * GET /api/crm/orders/{order}
     *
     * CRM-scoped equivalent of the Call Center order-details endpoint —
     * reuses CallCenterService::getOrderDetails() as-is (order, items,
     * invoice, feedback). Not nested under /customers/{customer}/, so
     * authorization is derived from the order's own customer instead of a
     * route-bound one.
     */
    public function orderDetails(Request $request, Order $order): JsonResponse
    {
        if ($order->customer_id) {
            $customer = Customer::findOrFail($order->customer_id);
            $this->access->authorize($request->user(), $customer);
        }

        return response()->json(['data' => $this->callCenter->getOrderDetails($order->id)]);
    }

    /**
     * GET /api/crm/orders/{order}/timeline
     *
     * CRM-scoped audit log for an order — reuses
     * OrderTimelineController::timeline() verbatim (who opened the order,
     * who added items, who printed/closed it) rather than rebuilding it.
     * Adds the same branch-scoped authorization as orderDetails() before
     * delegating, since the underlying controller has none of its own.
     */
    public function orderTimeline(Request $request, Order $order): JsonResponse
    {
        if ($order->customer_id) {
            $customer = Customer::findOrFail($order->customer_id);
            $this->access->authorize($request->user(), $customer);
        }

        return app(\App\Http\Controllers\Api\OrderTimelineController::class)->timeline($order);
    }
}
