<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmSetting;
use App\Models\CustomerComplaint;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemFeedback;
use App\Services\Crm\CrmCustomerAccessService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Real, computed-only analytics — every number here comes from orders/
 * order_items/order_item_feedback/customer_complaints, the same tables the
 * rest of the CRM already reads. Nothing invented: a widget with no honest
 * data source (revenue "forecast" as a model, satisfaction as a single
 * universal score) is either a plainly-labeled naive estimate or left out —
 * see the doc comment on each method for exactly which.
 */
class CrmReportController extends Controller
{
    public function __construct(private readonly CrmCustomerAccessService $access) {}

    /**
     * The branch every figure in this report must be confined to, or null for
     * a reader who legitimately sees all of them (super-admin / no branch).
     *
     * Order carries BranchScope, so anything built from `Order::query()` is
     * already confined. This exists for the two places that are NOT: a query
     * whose base model is OrderItem (the join to `orders` does not carry
     * Order's global scope) and CustomerComplaint (no global scope at all).
     * Without it a branch manager read their own branch's revenue next to a
     * company-wide department split in the same response.
     */
    private function scopedBranchId(Request $request): ?int
    {
        $user = $request->user();

        return $this->access->isGlobal($user) ? null : (int) $user->branch_id;
    }

    /**
     * The timezone the business actually lives in. The app stores and runs in
     * UTC (config/app.php 'timezone' => 'UTC'), so a naive HOUR(created_at)
     * or DATE(created_at) groups by UTC — which put every "peak hour" three
     * hours early and could file a 01:00 order under the previous day.
     * Every day/hour/weekday bucket below goes through localTs() instead,
     * and every period boundary is computed here and converted to UTC only
     * at bind time (see utc()).
     */
    private const BUSINESS_TZ = 'Asia/Gaza';

    /** SQL expression for created_at shifted into BUSINESS_TZ. Numeric offset, so it needs no MySQL tz tables; DST-correct for the offset in force now. */
    private function localTs(string $column = 'created_at'): string
    {
        $offset = now(self::BUSINESS_TZ)->format('P');

        return "CONVERT_TZ({$column}, '+00:00', '{$offset}')";
    }

    /** A local-time boundary as the UTC instant the database actually compares against. */
    private function utc(Carbon $local): Carbon
    {
        return $local->copy()->utc();
    }

    /** "Paid", the same derived signal every other CRM screen uses: status='paid' OR payment_status='paid'. */
    private function applyPaidFilter(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('status', 'paid')->orWhere('payment_status', 'paid'));
    }

    /**
     * [$from, $to, $prevFrom, $prevTo, $label] for a named period, all
     * inclusive-day Carbon instances in BUSINESS_TZ. The "previous" window
     * is always the same number of days immediately before $from — a fair
     * comparison for every period type without special-casing "month" vs
     * "week".
     */
    private function resolvePeriod(string $period): array
    {
        $now = now(self::BUSINESS_TZ);
        [$from, $to, $label] = match ($period) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay(), 'اليوم'],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfDay(), 'هذا الأسبوع'],
            'quarter' => [$now->copy()->startOfQuarter(), $now->copy()->endOfDay(), 'هذا الربع'],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfDay(), 'هذا العام'],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfDay(), 'هذا الشهر'],
        };

        // Carbon 3 returns a float here (e.g. 18.99998 for start-of-day →
        // end-of-day); whole days are what the window arithmetic needs.
        $lengthDays = (int) floor($from->diffInDays($to)) + 1;
        $prevTo = $from->copy()->subSecond();
        $prevFrom = $prevTo->copy()->subDays($lengthDays - 1)->startOfDay();

        return [$from, $to, $prevFrom, $prevTo, $label];
    }

    private function periodParam(Request $request): string
    {
        return $request->validate([
            'period' => ['nullable', Rule::in(['today', 'week', 'month', 'quarter', 'year'])],
        ])['period'] ?? 'month';
    }

    /**
     * GET /api/crm/reports/overview
     *
     * KPIs + trend + order-type split + daily revenue (with a real, rule-
     * based anomaly flag: a day whose revenue falls 20%+ below its own
     * trailing-7-day average) + peak hours + an alerts list + a rule-based
     * "what to do next" panel — every entry in both derived only from the
     * metrics already computed here, no separate model.
     */
    public function overview(Request $request): JsonResponse
    {
        $periodKey = $this->periodParam($request);
        [$from, $to, $prevFrom, $prevTo, $label] = $this->resolvePeriod($periodKey);

        $trendPct = fn (float $now, float $before): ?float => $before > 0 ? round((($now - $before) / $before) * 100, 1) : null;

        $range = [$this->utc($from), $this->utc($to)];
        $prevRange = [$this->utc($prevFrom), $this->utc($prevTo)];

        $totalOrders = Order::whereBetween('created_at', $range)->count();
        $completedQuery = $this->applyPaidFilter(Order::whereBetween('created_at', $range));
        $completedCount = (clone $completedQuery)->count();
        $totalRevenue = (float) (clone $completedQuery)->sum('total');
        $aov = $completedCount > 0 ? $totalRevenue / $completedCount : 0.0;
        $cancelledCount = Order::whereBetween('created_at', $range)->where('status', 'cancelled')->count();
        $cancellationRate = $totalOrders > 0 ? round($cancelledCount / $totalOrders * 100, 1) : 0.0;

        $prevTotalOrders = Order::whereBetween('created_at', $prevRange)->count();
        $prevCompletedQuery = $this->applyPaidFilter(Order::whereBetween('created_at', $prevRange));
        $prevCompletedCount = (clone $prevCompletedQuery)->count();
        $prevRevenue = (float) (clone $prevCompletedQuery)->sum('total');
        $prevAov = $prevCompletedCount > 0 ? $prevRevenue / $prevCompletedCount : 0.0;
        $prevCancelledCount = Order::whereBetween('created_at', $prevRange)->where('status', 'cancelled')->count();
        $prevCancellationRate = $prevTotalOrders > 0 ? round($prevCancelledCount / $prevTotalOrders * 100, 1) : 0.0;

        // Satisfaction — honestly a rating average over whatever got rated in
        // the period, not a universal score every order contributes to.
        $feedback = OrderItemFeedback::whereBetween('created_at', $range);
        $ratedCount = (clone $feedback)->count();
        $avgRating = $ratedCount > 0 ? (float) (clone $feedback)->avg('rating') : null;
        $satisfactionPct = $avgRating !== null ? round($avgRating / 5 * 100, 1) : null;
        $highRatedPct = $ratedCount > 0 ? round((clone $feedback)->where('rating', '>=', 4)->count() / $ratedCount * 100, 1) : null;

        $typeDistribution = (clone $completedQuery)
            ->selectRaw('order_type, COUNT(*) as orders_count, SUM(total) as revenue')
            ->groupBy('order_type')
            ->get()
            ->map(fn ($row) => ['order_type' => $row->order_type, 'orders_count' => (int) $row->orders_count, 'revenue' => (float) $row->revenue]);

        $dailyRevenue = $this->dailyRevenueWithAnomalies($completedQuery, $from, $to);

        $peakHours = $this->peakHours($completedQuery);

        $settings = CrmSetting::current();

        $alerts = $this->buildAlerts($dailyRevenue, $cancellationRate, $settings, $this->scopedBranchId($request));
        $decisions = $this->buildDecisionSupport($alerts, $cancellationRate, $settings, $trendPct($totalRevenue, $prevRevenue));

        return response()->json(['data' => [
            'period' => ['key' => $periodKey, 'label' => $label, 'from' => $from->toDateString(), 'to' => $to->toDateString()],
            'kpis' => [
                'revenue' => ['value' => $totalRevenue, 'trend_pct' => $trendPct($totalRevenue, $prevRevenue), 'target' => $settings->monthly_revenue_target],
                'aov' => ['value' => round($aov, 2), 'trend_pct' => $trendPct($aov, $prevAov)],
                'orders' => [
                    'completed' => $completedCount, 'total' => $totalOrders,
                    'completion_rate' => $totalOrders > 0 ? round($completedCount / $totalOrders * 100, 1) : 0,
                    'trend_pct' => $trendPct($completedCount, $prevCompletedCount),
                ],
                'satisfaction' => ['pct' => $satisfactionPct, 'high_rated_pct' => $highRatedPct, 'rated_count' => $ratedCount],
                'cancellation_rate' => ['pct' => $cancellationRate, 'target' => $settings->max_cancellation_rate_pct, 'trend_pct' => $trendPct($cancellationRate, $prevCancellationRate)],
            ],
            'order_type_distribution' => $typeDistribution,
            'daily_revenue' => $dailyRevenue,
            'peak_hours' => $peakHours,
            'alerts' => $alerts,
            'decision_support' => $decisions,
        ]]);
    }

    /** One row per day in [$from,$to], zero-filled, each carrying its own trailing-7-day average and a deviation flag. */
    private function dailyRevenueWithAnomalies(Builder $completedQuery, Carbon $from, Carbon $to): array
    {
        $rows = (clone $completedQuery)
            ->selectRaw("DATE({$this->localTs()}) as d, SUM(total) as revenue")
            ->groupBy('d')
            ->pluck('revenue', 'd');

        $days = [];
        $cursor = $from->copy()->startOfDay();
        while ($cursor->lte($to)) {
            $days[] = ['date' => $cursor->toDateString(), 'revenue' => (float) ($rows[$cursor->toDateString()] ?? 0)];
            $cursor->addDay();
        }

        foreach ($days as $i => &$day) {
            $window = array_slice($days, max(0, $i - 7), min(7, $i));
            $avg = count($window) > 0 ? array_sum(array_column($window, 'revenue')) / count($window) : null;
            $day['trailing_avg'] = $avg !== null ? round($avg, 2) : null;
            $day['deviation_pct'] = $avg && $avg > 0 ? round((($day['revenue'] - $avg) / $avg) * 100, 1) : null;
            // 20%+ below its own recent trend, and the window is long enough
            // to mean something (at least 3 prior days) — a single slow day
            // right after opening never flags on day 2.
            $day['is_anomaly'] = count($window) >= 3 && $day['deviation_pct'] !== null && $day['deviation_pct'] <= -20;
        }
        unset($day);

        return $days;
    }

    private function peakHours(Builder $completedQuery): array
    {
        $rows = (clone $completedQuery)->selectRaw("HOUR({$this->localTs()}) as h, COUNT(*) as c")->groupBy('h')->pluck('c', 'h');

        return collect(range(0, 23))->map(fn ($h) => ['hour' => $h, 'orders' => (int) ($rows[$h] ?? 0)])->values()->all();
    }

    /** Every alert names a real number and where it came from — no generic "something looks off". */
    private function buildAlerts(array $dailyRevenue, float $cancellationRate, CrmSetting $settings, ?int $branchId): array
    {
        $alerts = [];

        // CustomerComplaint has no global scope, so the branch confinement is
        // explicit here. A CRM-raised complaint carries no branch of its own
        // (customer_id is the only link), so a branch reader counts complaints
        // stamped with their branch OR belonging to one of their customers —
        // the same two-sided rule ComplaintController::scoped() applies.
        $deliveryComplaints48h = CustomerComplaint::where('department', CustomerComplaint::DEPARTMENT_DELIVERY)
            ->where('created_at', '>=', now()->subHours(48))
            ->when($branchId !== null, fn ($q) => $q->where(fn ($inner) => $inner
                ->where('branch_id', $branchId)
                ->orWhereHas('customer', fn ($c) => $c->where('branch_id', $branchId))))
            ->count();
        if ($deliveryComplaints48h > 0) {
            $alerts[] = ['level' => 'urgent', 'message' => "{$deliveryComplaints48h} شكاوى تتعلق بالتوصيل خلال آخر 48 ساعة"];
        }

        $worstAnomaly = collect($dailyRevenue)->filter(fn ($d) => $d['is_anomaly'])->sortBy('deviation_pct')->first();
        if ($worstAnomaly) {
            $alerts[] = [
                'level' => 'attention',
                'message' => 'انخفاض حاد في إيرادات يوم ' . $worstAnomaly['date'] . ' بنسبة ' . abs($worstAnomaly['deviation_pct']) . '% عن متوسط الأيام السابقة',
            ];
        }

        if ($settings->max_cancellation_rate_pct !== null && $cancellationRate > $settings->max_cancellation_rate_pct) {
            $alerts[] = [
                'level' => 'urgent',
                'message' => "معدل إلغاء الطلبات {$cancellationRate}% تجاوز الحد المسموح ({$settings->max_cancellation_rate_pct}%)",
            ];
        }

        return $alerts;
    }

    /** Rule-based, not ML — the same "confidence is a stricter pass of the same rule" philosophy CustomerGroupController::smartSuggestions() already uses. */
    private function buildDecisionSupport(array $alerts, float $cancellationRate, CrmSetting $settings, ?float $revenueTrendPct): array
    {
        $decisions = [];

        foreach ($alerts as $alert) {
            if ($alert['level'] === 'urgent') {
                $decisions[] = ['priority' => 'urgent', 'message' => $alert['message']];
            }
        }

        $approachingLimit = $settings->max_cancellation_rate_pct !== null
            ? $cancellationRate > $settings->max_cancellation_rate_pct * 0.7 && $cancellationRate <= $settings->max_cancellation_rate_pct
            : $cancellationRate >= 5;
        if ($approachingLimit) {
            $decisions[] = ['priority' => 'attention', 'message' => "معدل الإلغاء {$cancellationRate}% يقترب من الحد — راقبه قبل أن يتجاوزه"];
        }

        if ($revenueTrendPct !== null && $revenueTrendPct >= 10) {
            $decisions[] = ['priority' => 'opportunity', 'message' => "الإيرادات ارتفعت {$revenueTrendPct}% عن الفترة السابقة — فرصة لتوسيع ما ينجح حاليًا"];
        }

        if (empty($decisions)) {
            $decisions[] = ['priority' => 'opportunity', 'message' => 'لا توجد مشاكل عاجلة حاليًا — الأداء ضمن النطاق الطبيعي.'];
        }

        return $decisions;
    }

    /**
     * GET /api/crm/reports/revenue
     *
     * The current calendar month, always — this is a monthly detail report,
     * not a period-switchable one. Weekly buckets, best/worst weekday, top
     * days, department revenue split (order_items.department_id — the real
     * substitute for a "category" this app has never modelled), an hour×
     * weekday heatmap, and a plainly-labelled naive end-of-month range
     * (current pace × remaining days) — not a statistical forecast.
     */
    public function revenue(Request $request): JsonResponse
    {
        $from = now(self::BUSINESS_TZ)->startOfMonth();
        $to = now(self::BUSINESS_TZ)->endOfDay();
        $daysInMonth = $from->daysInMonth;
        $daysElapsed = (int) floor($from->diffInDays($to)) + 1;

        $completedQuery = fn () => $this->applyPaidFilter(Order::whereBetween('created_at', [$this->utc($from), $this->utc($to)]));
        $totalRevenue = (float) $completedQuery()->sum('total');

        $weekdayLabels = [1 => 'الأحد', 2 => 'الاثنين', 3 => 'الثلاثاء', 4 => 'الأربعاء', 5 => 'الخميس', 6 => 'الجمعة', 7 => 'السبت'];

        // How many times each weekday has actually occurred so far this
        // month — early in a month one weekday may have happened twice and
        // another once, so raw per-weekday sums are not comparable. Each
        // weekday's `revenue` below is its average per occurrence, and
        // best/worst rank on that; `weekday_average` is the mean of those,
        // i.e. a genuine per-day figure rather than a mean of month sums.
        $occurrences = array_fill(1, 7, 0);
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $occurrences[$d->dayOfWeekIso % 7 + 1]++; // ISO Mon=1..Sun=7 → MySQL Sun=1..Sat=7
        }

        $byWeekday = $completedQuery()
            ->selectRaw("DAYOFWEEK({$this->localTs()}) as dow, SUM(total) as revenue, COUNT(*) as orders_count")
            ->groupBy('dow')
            ->get()
            ->map(function ($r) use ($weekdayLabels, $occurrences) {
                $dow = (int) $r->dow;
                $times = max(1, $occurrences[$dow] ?? 1);

                return [
                    'dow' => $dow,
                    'label' => $weekdayLabels[$dow],
                    'revenue' => round((float) $r->revenue / $times, 2),
                    'revenue_total' => (float) $r->revenue,
                    'occurrences' => $times,
                    'orders_count' => (int) $r->orders_count,
                ];
            });
        $weekdayAvg = $byWeekday->count() > 0 ? $byWeekday->avg('revenue') : 0.0;
        $bestWeekday = $byWeekday->sortByDesc('revenue')->first();
        $worstWeekday = $byWeekday->sortBy('revenue')->first();

        $weeks = $this->weeklyBuckets($completedQuery, $from, $to, $daysInMonth);

        $topDays = $completedQuery()
            ->selectRaw("DATE({$this->localTs()}) as d, SUM(total) as revenue")
            ->groupBy('d')
            ->orderByDesc('revenue')
            ->limit(3)
            ->get()
            ->map(fn ($r) => ['date' => $r->d, 'revenue' => (float) $r->revenue]);

        // Base model is OrderItem, so joining `orders` does NOT bring Order's
        // BranchScope with it — the branch filter has to be explicit or a
        // branch reader sees every branch's departments beside their own
        // branch-confined revenue total.
        $branchId = $this->scopedBranchId($request);
        $departmentRevenue = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('orders.created_at', [$this->utc($from), $this->utc($to)])
            ->where(fn ($q) => $q->where('orders.status', 'paid')->orWhere('orders.payment_status', 'paid'))
            ->when($branchId !== null, fn ($q) => $q->where('orders.branch_id', $branchId))
            ->leftJoin('departments', 'departments.id', '=', 'order_items.department_id')
            ->selectRaw("COALESCE(departments.nameAr, departments.name, 'غير محدد') as department, SUM(order_items.total) as revenue")
            ->groupBy('department')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($r) => ['department' => $r->department, 'revenue' => (float) $r->revenue]);

        $heatmap = $this->hourWeekdayHeatmap($completedQuery, $weekdayLabels);

        $projection = $this->naiveProjection($totalRevenue, $daysInMonth, $daysElapsed);

        return response()->json(['data' => [
            'month' => $from->translatedFormat('F Y'),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_revenue' => $totalRevenue,
            'best_weekday' => $bestWeekday,
            'worst_weekday' => $worstWeekday,
            'weekday_average' => round($weekdayAvg, 2),
            'weeks' => $weeks,
            'top_days' => $topDays,
            'department_revenue' => $departmentRevenue,
            'heatmap' => $heatmap,
            'projection' => $projection,
        ]]);
    }

    private function weeklyBuckets(\Closure $completedQuery, Carbon $from, Carbon $to, int $daysInMonth): array
    {
        $weeks = [];
        foreach ([1, 8, 15, 22] as $i => $startDay) {
            if ($startDay > $daysInMonth) break;
            $endDay = min($startDay + 6, $daysInMonth);
            $weekFrom = $from->copy()->setDay($startDay)->startOfDay();
            if ($weekFrom->gt($to)) break; // week hasn't started yet this month
            $weekTo = $from->copy()->setDay($endDay)->endOfDay();
            if ($weekTo->gt($to)) $weekTo = $to->copy();

            $revenue = (float) $completedQuery()->whereBetween('created_at', [$this->utc($weekFrom), $this->utc($weekTo)])->sum('total');
            $weeks[] = [
                'label' => "الأسبوع " . ($i + 1) . " ({$startDay}-{$endDay})",
                'from' => $weekFrom->toDateString(), 'to' => $weekTo->toDateString(),
                'revenue' => $revenue,
                'in_progress' => $weekTo->isSameDay($to) && $endDay > (int) $to->day,
            ];
        }

        foreach ($weeks as $i => &$week) {
            $week['change_pct'] = $i > 0 && $weeks[$i - 1]['revenue'] > 0
                ? round((($week['revenue'] - $weeks[$i - 1]['revenue']) / $weeks[$i - 1]['revenue']) * 100, 1)
                : null;
        }
        unset($week);

        return $weeks;
    }

    private function hourWeekdayHeatmap(\Closure $completedQuery, array $weekdayLabels): array
    {
        $rows = $completedQuery()
            ->selectRaw("DAYOFWEEK({$this->localTs()}) as dow, HOUR({$this->localTs()}) as hour, COUNT(*) as c")
            ->groupBy('dow', 'hour')
            ->get();

        $byKey = $rows->keyBy(fn ($r) => $r->dow . '-' . $r->hour);

        $matrix = [];
        foreach ($weekdayLabels as $dow => $label) {
            $hours = [];
            for ($h = 0; $h < 24; $h++) {
                $hours[] = (int) ($byKey->get($dow . '-' . $h)->c ?? 0);
            }
            $matrix[] = ['dow' => $dow, 'label' => $label, 'hours' => $hours];
        }

        return $matrix;
    }

    /**
     * A naive pace-based range, explicitly not a statistical forecast: the
     * low bound extrapolates the slower of the last-7-day / last-30-day
     * daily average, the high bound the faster one, over the days left in
     * the month. Labelled as an estimate on the frontend, never "forecast".
     */
    private function naiveProjection(float $monthToDateRevenue, int $daysInMonth, int $daysElapsed): array
    {
        $remainingDays = max(0, $daysInMonth - $daysElapsed);

        $last7Avg = (float) $this->applyPaidFilter(Order::where('created_at', '>=', now()->subDays(7)))->sum('total') / 7;
        $last30Avg = (float) $this->applyPaidFilter(Order::where('created_at', '>=', now()->subDays(30)))->sum('total') / 30;

        $low = $monthToDateRevenue + min($last7Avg, $last30Avg) * $remainingDays;
        $high = $monthToDateRevenue + max($last7Avg, $last30Avg) * $remainingDays;

        return ['low' => round($low, 2), 'high' => round($high, 2)];
    }
}
