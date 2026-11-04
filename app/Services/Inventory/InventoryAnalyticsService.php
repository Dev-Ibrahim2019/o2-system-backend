<?php

namespace App\Services\Inventory;

use App\Models\InventorySetting;
use App\Models\Item;
use App\Models\Recipe;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * تحليلات المخزون: الاستهلاك الفعلي مقابل النظري، وتفصيله حسب الوصفات، وقيم المخزون وحركته.
 *
 * التجميع كله في SQL؛ PHP يربط النتائج المجمّعة فقط (مبيعات مجمّعة بالساعة، حركات مجمّعة باليوم).
 * كل الكميات بالوحدة الأساسية للصنف، والفترة بالأيام المحاسبية (02:00 → 02:00).
 *
 * التعريفات:
 *  - النظري  = Σ (الكمية المباعة من كل منتج × كمية المكوّن لكل وحدة في الوصفة السارية يوم البيع).
 *              المُباع: طلبات مدفوعة (payment_status = paid) غير محذوفة، والأسطر غير الملغاة، بيوم الدفع.
 *              يُنسب للمخزن المربوط بقسم السطر في فرع الطلب، وإلا لمخزن البيع الافتراضي للفرع.
 *  - الفعلي  = رصيد أول المدة + المشتريات + التحويلات الواردة − التحويلات الصادرة − رصيد آخر المدة.
 *              وبما أن رصيد آخر المدة = أول المدة + كل حركات الفترة، فالفعلي = −Σ حركات الاستهلاك
 *              (بيع، هالك، تسوية جرد، صرف إنتاج). الجرد المرحّل هو ما يجعل رصيد آخر المدة حقيقياً.
 *  - القيمة  = الكمية × تكلفة الوحدة الحالية للصنف في المخزن (متوسط مرجّح)، للفعلي والنظري معاً،
 *              حتى لا يظهر فرق قيمة سببه اختلاف التكلفة لا اختلاف الكمية.
 */
class InventoryAnalyticsService
{
    private const S = 12;

    /** حركات الاستهلاك: صادرها موجب في "الفعلي". */
    private const CONSUMPTION = ['sale', 'waste', 'adjustment', 'production_out'];

    private AccountingDay $day;

    /** @var array<string, array<int, Recipe>>|null نسخ الوصفات لكل مالك (p:item | s:stock_item) مرتبة تصاعدياً */
    private ?array $book = null;

    /** @var array<string, array<int, string>> */
    private array $explodeCache = [];

    public function __construct(private RecipeService $recipes, private WarehouseAccess $access)
    {
        $this->day = AccountingDay::fromConfig();
    }

    // ── الفلاتر ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $in  period(today|week|month|custom), from, to, warehouse_id, category_id, stock_item_id, stagnant_days
     * @return array{from:string, to:string, days:int, warehouses:?array<int>, items:?array<int>, threshold:string, stagnant_days:int, stock_item_id:?int}
     */
    public function filters(array $in, User $user): array
    {
        $today = $this->day->of(now());
        [$from, $to] = match ($in['period'] ?? 'month') {
            'today' => [$today, $today],
            'week' => [CarbonImmutable::parse($today)->subDays(6)->toDateString(), $today],
            'custom' => [$in['from'] ?? $today, $in['to'] ?? $today],
            default => [CarbonImmutable::parse($today)->startOfMonth()->toDateString(), $today],
        };
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $warehouses = ! empty($in['warehouse_id'])
            ? [(int) $in['warehouse_id']]
            : ($this->access->hasGlobalAccess($user) ? null : $this->access->assignedIds($user));

        $items = null;
        if (! empty($in['stock_item_id'])) {
            $items = [(int) $in['stock_item_id']];
        } elseif (! empty($in['category_id'])) {
            $items = DB::table('stock_category_item')->where('stock_category_id', (int) $in['category_id'])
                ->pluck('stock_item_id')->map(fn ($id) => (int) $id)->all();
        }

        return [
            'from' => $from,
            'to' => $to,
            'days' => (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1,
            'warehouses' => $warehouses,
            'items' => $items,
            'threshold' => (string) (InventorySetting::current()->variance_alert_percent ?? '3'),
            'stagnant_days' => max(1, (int) ($in['stagnant_days'] ?? 30)),
            'stock_item_id' => ! empty($in['stock_item_id']) ? (int) $in['stock_item_id'] : null,
        ];
    }

    // ── الفعلي مقابل النظري ───────────────────────────────────────────────────

    /**
     * جدول الفرق لكل صنف (مجمّعاً على المخازن المختارة)، مرتباً بأعلى فرق قيمة مطلق.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, mixed>, unrecipe: array<int, array<string, mixed>>, unattributed_qty: string}
     */
    public function variance(array $f): array
    {
        $moves = $this->movementSummary($f);
        $theo = $this->theoretical($f);

        $theoByWi = [];
        foreach ($theo['contrib'] as $key => $qty) {
            [$w, , $i] = explode('|', $key);
            $theoByWi["{$w}:{$i}"] = $this->add($theoByWi["{$w}:{$i}"] ?? '0', $qty);
        }

        $keys = array_values(array_unique([...array_keys($moves), ...array_keys($theoByWi)]));
        $costs = $this->unitCosts($keys);
        $fields = ['opening', 'inflow', 'transfers_in', 'transfers_out', 'closing', 'waste', 'adjustment', 'used'];

        $byItem = [];
        foreach ($keys as $wi) {
            $i = (int) explode(':', $wi)[1];
            $m = $moves[$wi] ?? [];
            $actual = $m['actual'] ?? '0';
            $theoQty = $theoByWi[$wi] ?? '0';
            $cost = $costs[$wi] ?? '0';
            $row = $byItem[$i] ?? array_fill_keys([...$fields, 'actual_qty', 'theoretical_qty', 'actual_value', 'theoretical_value'], '0');
            foreach ($fields as $k) {
                $row[$k] = $this->add($row[$k], $m[$k] ?? '0');
            }
            $row['actual_qty'] = $this->add($row['actual_qty'], $actual);
            $row['theoretical_qty'] = $this->add($row['theoretical_qty'], $theoQty);
            // القيمة = الكمية × تكلفة الوحدة في هذا المخزن (ثم تُجمع القيم على المخازن)
            $row['actual_value'] = $this->add($row['actual_value'], bcmul($actual, $cost, self::S));
            $row['theoretical_value'] = $this->add($row['theoretical_value'], bcmul($theoQty, $cost, self::S));
            $byItem[$i] = $row;
        }

        $meta = $this->itemMeta(array_keys($byItem));
        $lastCounts = $this->lastCountDates($f, array_keys($byItem));

        $rows = [];
        $totals = ['actual_value' => '0', 'theoretical_value' => '0'];
        foreach ($byItem as $i => $row) {
            if (! isset($meta[$i])) {
                continue;
            }
            // الفرق = الفعلي − النظري؛ والنسبة = الفرق ÷ النظري × 100
            $vQty = bcsub($row['actual_qty'], $row['theoretical_qty'], self::S);
            $vVal = bcsub($row['actual_value'], $row['theoretical_value'], self::S);
            $pct = bccomp($row['theoretical_qty'], '0', 6) > 0 ? bcdiv(bcmul($vQty, '100', self::S), $row['theoretical_qty'], 2) : null;
            $formatted = [];
            foreach ($row as $k => $v) {
                $formatted[$k] = str_ends_with($k, '_value') ? $this->out($v, 2) : $this->out($v);
            }
            $rows[] = [
                'stock_item_id' => $i,
                ...$meta[$i],
                ...$formatted,
                'variance_qty' => $this->out($vQty),
                'variance_value' => $this->out($vVal, 2),
                'variance_percent' => $pct,
                // تنبيه: الفعلي أعلى من النظري بأكثر من الحد، أو استهلاك فعلي بلا مبيعات تستهلكه.
                'alert' => $pct !== null ? bccomp($pct, $f['threshold'], 2) > 0 : bccomp($row['actual_qty'], '0', 6) > 0,
                'last_count_date' => $lastCounts[$i] ?? null,
            ];
            $totals['actual_value'] = $this->add($totals['actual_value'], $row['actual_value']);
            $totals['theoretical_value'] = $this->add($totals['theoretical_value'], $row['theoretical_value']);
        }
        usort($rows, fn ($a, $b) => abs((float) $b['variance_value']) <=> abs((float) $a['variance_value']));

        $varianceTotal = bcsub($totals['actual_value'], $totals['theoretical_value'], self::S);
        $pctTotal = bccomp($totals['theoretical_value'], '0', 6) > 0
            ? bcdiv(bcmul($varianceTotal, '100', self::S), $totals['theoretical_value'], 2) : null;

        $names = Item::whereIn('id', array_keys($theo['unrecipe']))->get(['id', 'name', 'name_ar'])->keyBy('id');

        return [
            'rows' => $rows,
            'totals' => [
                'actual_value' => $this->out($totals['actual_value'], 2),
                'theoretical_value' => $this->out($totals['theoretical_value'], 2),
                'variance_value' => $this->out($varianceTotal, 2),
                'variance_percent' => $pctTotal,
                'alert' => $pctTotal !== null && bccomp($pctTotal, $f['threshold'], 2) > 0,
                'threshold' => $f['threshold'],
            ],
            'unrecipe' => collect($theo['unrecipe'])->map(fn ($qty, $p) => [
                'item_id' => (int) $p,
                'name' => $names[$p]->name_ar ?? $names[$p]->name ?? '',
                'sold' => $this->out($qty, 2),
            ])->sortByDesc(fn ($r) => (float) $r['sold'])->values()->all(),
            'unattributed_qty' => $this->out($theo['unattributed'], 2),
        ];
    }

    /** سلسلة يومية لصنف: النظري والفعلي، وتغيّر متوسط التكلفة لكل مخزن. */
    public function itemSeries(array $f, int $stockItemId): array
    {
        $f['items'] = [$stockItemId];
        $theo = $this->theoretical($f);
        $theoDay = [];
        foreach ($theo['contrib'] as $key => $qty) {
            $day = explode('|', $key)[3];
            $theoDay[$day] = $this->add($theoDay[$day] ?? '0', $qty);
        }

        $actualDay = $this->movements($f)
            ->whereIn('type', self::CONSUMPTION)
            ->groupBy('accounting_day')
            ->selectRaw('accounting_day, -SUM(quantity) as qty')
            ->pluck('qty', 'accounting_day');

        $days = [];
        foreach ($this->dayList($f) as $d) {
            $days[] = [
                'day' => $d,
                'theoretical' => (float) $this->out($theoDay[$d] ?? '0'),
                'actual' => (float) $this->out((string) ($actualDay[$d] ?? '0')),
            ];
        }

        // متوسط التكلفة في نهاية كل يوم فيه حركة: آخر لقطة avg_cost_after في اليوم لكل مخزن.
        $lastIds = $this->movements($f)->groupBy('warehouse_id', 'accounting_day')->selectRaw('MAX(id) as id')->pluck('id');
        $points = DB::table('stock_movements as m')
            ->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->whereIn('m.id', $lastIds->all() ?: [0])
            ->orderBy('m.accounting_day')
            ->get(['m.accounting_day', 'm.warehouse_id', 'w.name as warehouse', 'm.avg_cost_after']);

        return [
            'item' => $this->itemMeta([$stockItemId])[$stockItemId] ?? null,
            'days' => $days,
            'avg_cost' => $points->map(fn ($p) => [
                'day' => (string) $p->accounting_day,
                'warehouse_id' => (int) $p->warehouse_id,
                'warehouse' => $p->warehouse,
                'avg_cost' => (float) $p->avg_cost_after,
            ])->all(),
        ];
    }

    /** المكوّن ← المنتجات: كم استهلك كل منتج منيو من هذا المكوّن في الفترة. */
    public function ingredientBreakdown(array $f, int $stockItemId): array
    {
        $f['items'] = [$stockItemId];
        $theo = $this->theoretical($f);
        $costs = $this->unitCosts($this->wiKeys($theo['contrib']));

        $byProduct = [];
        $total = '0';
        foreach ($theo['contrib'] as $key => $qty) {
            [$w, $p, $i] = explode('|', $key);
            $byProduct[$p]['consumed'] = $this->add($byProduct[$p]['consumed'] ?? '0', $qty);
            $byProduct[$p]['cost'] = $this->add($byProduct[$p]['cost'] ?? '0', bcmul($qty, $costs["{$w}:{$i}"] ?? '0', self::S));
            $total = $this->add($total, $qty);
        }
        $sold = $this->soldByProduct($theo['sold']);
        $names = $this->productNames(array_keys($byProduct));

        $rows = [];
        $costTotal = '0';
        foreach ($byProduct as $p => $r) {
            $s = $sold[$p] ?? '0';
            $costTotal = $this->add($costTotal, $r['cost']);
            $rows[] = [
                'item_id' => is_numeric($p) ? (int) $p : null,
                'product_key' => (string) $p,
                'name' => $names[(string) $p]['name'] ?? '',
                'code' => $names[(string) $p]['code'] ?? null,
                'sold' => $this->out($s, 2),
                // الكمية لكل وحدة = المستهلك ÷ المباع (متوسط الفترة إن تغيّرت الوصفة خلالها)
                'per_unit' => bccomp($s, '0', 6) > 0 ? $this->out(bcdiv($r['consumed'], $s, self::S)) : null,
                'consumed' => $this->out($r['consumed']),
                'cost' => $this->out($r['cost'], 2),
                // نسبة المنتج = ما استهلكه ÷ إجمالي استهلاك المكوّن × 100
                'share_percent' => bccomp($total, '0', 6) > 0 ? bcdiv(bcmul($r['consumed'], '100', self::S), $total, 2) : '0.00',
            ];
        }
        usort($rows, fn ($a, $b) => (float) $b['consumed'] <=> (float) $a['consumed']);

        return [
            'item' => $this->itemMeta([$stockItemId])[$stockItemId] ?? null,
            'total_consumed' => $this->out($total),
            'total_cost' => $this->out($costTotal, 2),
            'rows' => $rows,
        ];
    }

    /** المنتج ← المكوّنات: ما استهلكه منتج منيو من كل مكوّن، وتكلفة الوصفة الفعلية لكل وحدة. */
    public function productBreakdown(array $f, int $productId): array
    {
        $f['items'] = null;
        $theo = $this->theoretical($f, $productId);
        $costs = $this->unitCosts($this->wiKeys($theo['contrib']));
        $sold = $this->soldByProduct($theo['sold'])[$productId] ?? '0';

        $byItem = [];
        foreach ($theo['contrib'] as $key => $qty) {
            [$w, , $i] = explode('|', $key);
            $byItem[$i]['consumed'] = $this->add($byItem[$i]['consumed'] ?? '0', $qty);
            $byItem[$i]['cost'] = $this->add($byItem[$i]['cost'] ?? '0', bcmul($qty, $costs["{$w}:{$i}"] ?? '0', self::S));
        }
        $meta = $this->itemMeta(array_map('intval', array_keys($byItem)));
        $hasSold = bccomp($sold, '0', 6) > 0;

        $rows = [];
        $costTotal = '0';
        foreach ($byItem as $i => $r) {
            $costTotal = $this->add($costTotal, $r['cost']);
            $rows[] = [
                'stock_item_id' => (int) $i,
                ...($meta[$i] ?? ['name' => '', 'code' => null, 'base_unit' => null, 'display_unit' => null]),
                'per_unit' => $hasSold ? $this->out(bcdiv($r['consumed'], $sold, self::S)) : null,
                'consumed' => $this->out($r['consumed']),
                // تكلفة وحدة المكوّن = تكلفة المستهلك ÷ كميته
                'unit_cost' => bccomp($r['consumed'], '0', 6) > 0 ? $this->out(bcdiv($r['cost'], $r['consumed'], self::S)) : null,
                'cost' => $this->out($r['cost'], 2),
                'cost_per_product' => $hasSold ? $this->out(bcdiv($r['cost'], $sold, self::S), 4) : null,
            ];
        }
        usort($rows, fn ($a, $b) => (float) $b['cost'] <=> (float) $a['cost']);

        $item = Item::find($productId, ['id', 'name', 'name_ar', 'code']);
        $price = DB::table('branch_item')->where('item_id', $productId)->where('is_active', true)->whereNotNull('price')->avg('price');
        // تكلفة الوصفة الفعلية لكل وحدة = تكلفة المكوّنات المستهلكة ÷ الكمية المباعة
        $unitCost = $hasSold ? bcdiv($costTotal, $sold, self::S) : null;

        return [
            'product' => $item ? ['id' => $item->id, 'name' => $item->name_ar ?: $item->name, 'code' => $item->code] : null,
            'sold' => $this->out($sold, 2),
            'has_recipe' => ! isset($theo['unrecipe'][$productId]),
            'total_cost' => $this->out($costTotal, 2),
            'unit_cost' => $unitCost !== null ? $this->out($unitCost, 4) : null,
            'price' => $price !== null ? number_format((float) $price, 3, '.', '') : null,
            'food_cost_percent' => $unitCost !== null && $price ? bcdiv(bcmul($unitCost, '100', self::S), (string) $price, 2) : null,
            'rows' => $rows,
        ];
    }

    // ── النظرة العامة: KPIs ومخططات القيمة والحركة ─────────────────────────────

    public function overview(array $f): array
    {
        $variance = $this->variance($f);
        $balances = $this->balances($f);

        $stockValue = (string) (clone $balances)->selectRaw('COALESCE(SUM(b.quantity * b.avg_cost), 0) as v')->value('v');
        $belowSafety = (clone $balances)->join('stock_items as si', 'si.id', '=', 'b.stock_item_id')
            ->where('si.safety_stock', '>', 0)->whereColumn('b.quantity', '<', 'si.safety_stock')->count();

        // قيمة المخزون في أي يوم = Σ قيم الحركات حتى ذلك اليوم (قيمة الحركة = الكمية × تكلفتها)
        $openingValue = (string) $this->movements($f, false)->where('accounting_day', '<', $f['from'])->sum('total_cost');
        $daily = $this->movements($f)->groupBy('accounting_day')
            ->selectRaw("accounting_day,
                SUM(CASE WHEN type IN ('purchase','opening','production_in') THEN total_cost ELSE 0 END) as inflow,
                SUM(CASE WHEN type IN ('sale','production_out') THEN -total_cost ELSE 0 END) as outflow,
                SUM(CASE WHEN type = 'transfer_in' THEN total_cost ELSE 0 END) as transfers_in,
                SUM(CASE WHEN type = 'transfer_out' THEN -total_cost ELSE 0 END) as transfers_out,
                SUM(CASE WHEN type = 'waste' THEN -total_cost ELSE 0 END) as waste,
                SUM(CASE WHEN type = 'adjustment' THEN total_cost ELSE 0 END) as adjustment,
                SUM(total_cost) as net")
            ->get()->keyBy('accounting_day');

        $series = [];
        $trend = [];
        $running = $openingValue;
        foreach ($this->dayList($f) as $d) {
            $r = $daily[$d] ?? null;
            $series[] = [
                'day' => $d,
                'inflow' => round((float) ($r->inflow ?? 0), 2),
                'outflow' => round((float) ($r->outflow ?? 0), 2),
                'transfers_in' => round((float) ($r->transfers_in ?? 0), 2),
                'transfers_out' => round((float) ($r->transfers_out ?? 0), 2),
                'waste' => round((float) ($r->waste ?? 0), 2),
                'adjustment' => round((float) ($r->adjustment ?? 0), 2),
            ];
            $running = $this->add($running, (string) ($r->net ?? '0'));
            $trend[] = ['day' => $d, 'value' => round((float) $running, 2)];
        }

        // دوران المخزون = تكلفة الاستهلاك الفعلي ÷ متوسط قيمة المخزون ((أول المدة + آخر المدة) ÷ 2)
        // أيام التغطية = قيمة آخر المدة ÷ متوسط تكلفة الاستهلاك اليومي
        $consumption = $variance['totals']['actual_value'];
        $avgInventory = bcdiv($this->add($openingValue, $running), '2', self::S);
        $turnover = bccomp($avgInventory, '0', 6) > 0 && bccomp($consumption, '0', 6) > 0 ? bcdiv($consumption, $avgInventory, 2) : null;
        $perDay = bcdiv($consumption, (string) $f['days'], self::S);
        $coverage = bccomp($perDay, '0', 6) > 0 ? bcdiv($running, $perDay, 1) : null;

        $single = null;
        if ($f['stock_item_id']) {
            $row = collect($variance['rows'])->firstWhere('stock_item_id', $f['stock_item_id']);
            $single = $row ? [
                'actual_qty' => $row['actual_qty'], 'theoretical_qty' => $row['theoretical_qty'], 'variance_qty' => $row['variance_qty'],
                'base_unit' => $row['base_unit'], 'display_unit' => $row['display_unit'],
            ] : null;
        }

        return [
            'period' => ['from' => $f['from'], 'to' => $f['to'], 'days' => $f['days']],
            'kpis' => [
                'stock_value' => $this->out($stockValue, 2),
                'actual_value' => $variance['totals']['actual_value'],
                'theoretical_value' => $variance['totals']['theoretical_value'],
                'variance_value' => $variance['totals']['variance_value'],
                'variance_percent' => $variance['totals']['variance_percent'],
                'variance_alert' => $variance['totals']['alert'],
                'threshold' => $f['threshold'],
                'below_safety' => $belowSafety,
                'turnover' => $turnover,
                'coverage_days' => $coverage,
                'single_item' => $single,
                'products_without_recipe' => count($variance['unrecipe']),
            ],
            'value_by_warehouse' => (clone $balances)->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
                ->groupBy('b.warehouse_id', 'w.name')
                ->selectRaw('b.warehouse_id, w.name, ROUND(SUM(b.quantity * b.avg_cost), 2) as value')
                ->orderByDesc('value')->get()
                ->map(fn ($r) => ['warehouse_id' => (int) $r->warehouse_id, 'name' => $r->name, 'value' => (float) $r->value])->all(),
            'value_by_category' => $this->valueByCategory($balances),
            'movement_series' => $series,
            'value_trend' => $trend,
            'top_consumption' => collect($variance['rows'])
                ->filter(fn ($r) => (float) $r['actual_value'] > 0)
                ->sortByDesc(fn ($r) => (float) $r['actual_value'])->take(10)->values()
                ->map(fn ($r) => [
                    'stock_item_id' => $r['stock_item_id'], 'name' => $r['name'], 'value' => round((float) $r['actual_value'], 2),
                    'quantity' => $r['actual_qty'], 'base_unit' => $r['base_unit'],
                ])->all(),
            'low_stock' => $this->lowStock($f),
            'stagnant' => $this->stagnant($f),
        ];
    }

    /** الأصناف تحت مخزون الأمان أو قريبة منه (≤ 125% منه)، مع الكمية المقترحة والمورّد المفضّل. */
    public function lowStock(array $f): array
    {
        return $this->balances($f)
            ->join('stock_items as si', 'si.id', '=', 'b.stock_item_id')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->join('stock_units as u', 'u.id', '=', 'si.base_unit_id')
            ->leftJoin('supplier_stock_item as ss', fn ($j) => $j->on('ss.stock_item_id', '=', 'si.id')->where('ss.is_preferred', true))
            ->leftJoin('suppliers as s', 's.id', '=', 'ss.supplier_id')
            ->where('si.safety_stock', '>', 0)
            ->whereRaw('b.quantity <= si.safety_stock * 1.25')
            ->orderByRaw('b.quantity / si.safety_stock')
            ->limit(200)
            ->get(['b.stock_item_id', 'si.code', 'si.name', 'u.name as base_unit', 'b.warehouse_id', 'w.name as warehouse',
                'b.quantity', 'si.safety_stock', 's.name as supplier'])
            ->map(function ($r) {
                // الكمية المقترحة = ضعف مخزون الأمان − الرصيد الحالي (لا تقل عن صفر)
                $suggested = bcsub(bcmul((string) $r->safety_stock, '2', 6), (string) $r->quantity, 6);

                return [
                    'stock_item_id' => (int) $r->stock_item_id, 'code' => $r->code, 'name' => $r->name, 'base_unit' => $r->base_unit,
                    'warehouse_id' => (int) $r->warehouse_id, 'warehouse' => $r->warehouse,
                    'quantity' => $this->out((string) $r->quantity), 'safety_stock' => $this->out((string) $r->safety_stock),
                    'status' => bccomp((string) $r->quantity, (string) $r->safety_stock, 6) < 0 ? 'below' : 'near',
                    'suggested' => bccomp($suggested, '0', 6) > 0 ? $this->out($suggested) : '0.000000',
                    'supplier' => $r->supplier,
                ];
            })->all();
    }

    /** الأصناف الراكدة: رصيد موجب ولا حركة منذ X يوم. */
    public function stagnant(array $f): array
    {
        $today = $this->day->of(now());
        $cutoff = CarbonImmutable::parse($today)->subDays($f['stagnant_days'])->toDateString();
        $last = DB::table('stock_movements')->groupBy('warehouse_id', 'stock_item_id')
            ->selectRaw('warehouse_id, stock_item_id, MAX(accounting_day) as last_day');

        return $this->balances($f)
            ->where('b.quantity', '>', 0)
            ->joinSub($last, 'lm', fn ($j) => $j->on('lm.warehouse_id', '=', 'b.warehouse_id')->on('lm.stock_item_id', '=', 'b.stock_item_id'))
            ->join('stock_items as si', 'si.id', '=', 'b.stock_item_id')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->join('stock_units as u', 'u.id', '=', 'si.base_unit_id')
            ->where('lm.last_day', '<', $cutoff)
            ->orderBy('lm.last_day')
            ->limit(200)
            ->get(['b.stock_item_id', 'si.code', 'si.name', 'u.name as base_unit', 'w.name as warehouse', 'b.quantity',
                DB::raw('ROUND(b.quantity * b.avg_cost, 2) as value'), 'lm.last_day'])
            ->map(fn ($r) => [
                'stock_item_id' => (int) $r->stock_item_id, 'code' => $r->code, 'name' => $r->name, 'base_unit' => $r->base_unit,
                'warehouse' => $r->warehouse, 'quantity' => $this->out((string) $r->quantity), 'value' => (string) $r->value,
                'last_movement_day' => (string) $r->last_day,
                'idle_days' => (int) CarbonImmutable::parse((string) $r->last_day)->diffInDays(CarbonImmutable::parse($today)),
            ])->all();
    }

    // ── الحسابات الأساسية ─────────────────────────────────────────────────────

    /**
     * ملخص حركات الفترة لكل (مخزن، صنف) ورصيد أول المدة، من استعلامين مجمّعين.
     *
     * @return array<string, array<string, string>>  "w:i" => opening, inflow, transfers_in, transfers_out, waste, adjustment, used, closing, actual
     */
    public function movementSummary(array $f): array
    {
        $period = $this->movements($f)->groupBy('warehouse_id', 'stock_item_id')->selectRaw("warehouse_id, stock_item_id,
            SUM(CASE WHEN type IN ('purchase','opening','production_in') THEN quantity ELSE 0 END) as inflow,
            SUM(CASE WHEN type = 'transfer_in' THEN quantity ELSE 0 END) as transfers_in,
            SUM(CASE WHEN type = 'transfer_out' THEN -quantity ELSE 0 END) as transfers_out,
            SUM(CASE WHEN type = 'waste' THEN -quantity ELSE 0 END) as waste,
            SUM(CASE WHEN type = 'adjustment' THEN -quantity ELSE 0 END) as adjustment,
            SUM(CASE WHEN type IN ('sale','production_out') THEN -quantity ELSE 0 END) as used,
            SUM(quantity) as net")->get();
        $opening = $this->movements($f, false)->where('accounting_day', '<', $f['from'])
            ->groupBy('warehouse_id', 'stock_item_id')->selectRaw('warehouse_id, stock_item_id, SUM(quantity) as qty')->get();

        $zero = ['opening' => '0', 'inflow' => '0', 'transfers_in' => '0', 'transfers_out' => '0', 'waste' => '0', 'adjustment' => '0', 'used' => '0', 'net' => '0'];
        $out = [];
        foreach ($opening as $o) {
            $out["{$o->warehouse_id}:{$o->stock_item_id}"] = ['opening' => (string) $o->qty] + $zero;
        }
        foreach ($period as $p) {
            $key = "{$p->warehouse_id}:{$p->stock_item_id}";
            $out[$key] = [
                'opening' => $out[$key]['opening'] ?? '0',
                'inflow' => (string) $p->inflow, 'transfers_in' => (string) $p->transfers_in, 'transfers_out' => (string) $p->transfers_out,
                'waste' => (string) $p->waste, 'adjustment' => (string) $p->adjustment, 'used' => (string) $p->used, 'net' => (string) $p->net,
            ];
        }
        foreach ($out as $key => $r) {
            $r['closing'] = $this->add($r['opening'], $r['net']);
            // الفعلي = أول المدة + الوارد + المحوّل إليه − المحوّل منه − آخر المدة (= هالك + تسويات + بيع/إنتاج)
            $r['actual'] = bcsub(
                $this->add($this->add($r['opening'], $r['inflow']), bcsub($r['transfers_in'], $r['transfers_out'], self::S)),
                $r['closing'],
                self::S,
            );
            unset($r['net']);
            $out[$key] = $r;
        }

        return $out;
    }

    /**
     * الاستهلاك النظري مفصّلاً: مساهمة كل (مخزن، منتج، مكوّن، يوم).
     *
     * @return array{contrib: array<string, string>, sold: array<string, string>, unrecipe: array<int, string>, unattributed: string}
     *         contrib "w|p|i|day" => الكمية الأساسية؛ sold "w|p|day" => الكمية المباعة
     */
    public function theoretical(array $f, ?int $productId = null): array
    {
        [$start] = $this->day->bounds($f['from']);
        [, $end] = $this->day->bounds($f['to']);
        $hour = "DATE_FORMAT(o.paid_at, '%Y-%m-%d %H:00:00')";

        // المبيعات مجمّعة بالساعة (UTC): اليوم المحاسبي يبدأ على رأس ساعة، فالتجميع بالساعة لا يخلط يومين.
        $sales = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.payment_status', 'paid')
            ->whereNull('o.deleted_at')
            ->where('oi.status', '!=', 'cancelled')
            ->where('o.paid_at', '>=', $start->toDateTimeString())
            ->where('o.paid_at', '<', $end->toDateTimeString())
            ->when($productId, fn ($q) => $q->where('oi.item_id', $productId))
            ->groupBy('oi.item_id', 'oi.department_id', 'o.branch_id')
            ->groupByRaw($hour)
            ->selectRaw("oi.item_id, oi.department_id, o.branch_id, {$hour} as h, SUM(oi.quantity) as qty")
            ->get();

        [$deptMap, $defaults] = $this->salesWarehouses();
        $allowedW = $f['warehouses'] !== null ? array_flip($f['warehouses']) : null;
        $allowedI = $f['items'] !== null ? array_flip($f['items']) : null;

        $contrib = [];
        $sold = [];
        $unrecipe = [];
        $unattributed = '0';
        foreach ($sales as $s) {
            $w = $deptMap[$s->branch_id][$s->department_id] ?? $defaults[$s->branch_id] ?? null;
            $qty = (string) $s->qty;
            if ($w === null) {
                $unattributed = $this->add($unattributed, $qty);

                continue;
            }
            if ($allowedW !== null && ! isset($allowedW[$w])) {
                continue;
            }
            $day = $this->day->of(CarbonImmutable::parse($s->h, 'UTC'));
            $p = (int) $s->item_id;
            $sold["{$w}|{$p}|{$day}"] = $this->add($sold["{$w}|{$p}|{$day}"] ?? '0', $qty);

            $recipe = $this->bookVersion("p:{$p}", $day);
            if (! $recipe) {
                $unrecipe[$p] = $this->add($unrecipe[$p] ?? '0', $qty);

                continue;
            }
            foreach ($this->explodeCached($recipe, $day) as $i => $perUnit) {
                if ($allowedI !== null && ! isset($allowedI[$i])) {
                    continue;
                }
                // استهلاك المكوّن = الكمية المباعة × كمية المكوّن لكل وحدة
                $key = "{$w}|{$p}|{$i}|{$day}";
                $contrib[$key] = $this->add($contrib[$key] ?? '0', bcmul($qty, $perUnit, self::S));
            }
        }

        return ['contrib' => $contrib, 'sold' => $sold, 'unrecipe' => $unrecipe, 'unattributed' => $unattributed];
    }

    /** اسم "المنتج" في التفصيل: صنف منيو (رقم) أو منتج مصنع مخزني ("s{id}"). */
    private function productNames(array $keys): array
    {
        $menu = array_filter($keys, fn ($k) => ! str_starts_with((string) $k, 's'));
        $stock = array_map(fn ($k) => (int) substr((string) $k, 1), array_filter($keys, fn ($k) => str_starts_with((string) $k, 's')));
        $names = [];
        foreach (Item::whereIn('id', $menu ?: [0])->get(['id', 'name', 'name_ar', 'code']) as $i) {
            $names[(string) $i->id] = ['name' => $i->name_ar ?: $i->name, 'code' => $i->code];
        }
        foreach (StockItem::whereIn('id', $stock ?: [0])->get(['id', 'name', 'code']) as $i) {
            $names['s'.$i->id] = ['name' => $i->name.' (إنتاج)', 'code' => $i->code];
        }

        return $names;
    }

    // ── أدوات داخلية ──────────────────────────────────────────────────────────

    /** حركات الفترة (أو كل الحركات) مقيّدة بالمخازن والأصناف المختارة. */
    private function movements(array $f, bool $inPeriod = true): Builder
    {
        return DB::table('stock_movements')
            ->when($inPeriod, fn ($x) => $x->whereBetween('accounting_day', [$f['from'], $f['to']]))
            ->when($f['warehouses'] !== null, fn ($x) => $x->whereIn('warehouse_id', $f['warehouses'] ?: [0]))
            ->when($f['items'] !== null, fn ($x) => $x->whereIn('stock_item_id', $f['items'] ?: [0]));
    }

    private function balances(array $f): Builder
    {
        return DB::table('stock_balances as b')
            ->when($f['warehouses'] !== null, fn ($x) => $x->whereIn('b.warehouse_id', $f['warehouses'] ?: [0]))
            ->when($f['items'] !== null, fn ($x) => $x->whereIn('b.stock_item_id', $f['items'] ?: [0]));
    }

    /** @return array<int, array{category_id:?int, name:string, value:float}> التصنيف الأول للصنف (أصغر معرّف) حتى لا تُحسب القيمة مرتين. */
    private function valueByCategory(Builder $balances): array
    {
        $primary = DB::table('stock_category_item')->groupBy('stock_item_id')->selectRaw('stock_item_id, MIN(stock_category_id) as category_id');

        return (clone $balances)
            ->leftJoinSub($primary, 'pc', 'pc.stock_item_id', '=', 'b.stock_item_id')
            ->leftJoin('stock_categories as c', 'c.id', '=', 'pc.category_id')
            ->groupBy('c.id', 'c.name')
            ->selectRaw('c.id, c.name, ROUND(SUM(b.quantity * b.avg_cost), 2) as value')
            ->havingRaw('SUM(b.quantity * b.avg_cost) > 0')
            ->orderByDesc('value')
            ->get()
            ->map(fn ($r) => ['category_id' => $r->id ? (int) $r->id : null, 'name' => $r->name ?? 'بدون تصنيف', 'value' => (float) $r->value])
            ->all();
    }

    /**
     * خريطة نسب المبيعات للمخازن: [فرع][قسم] → مخزن القسم، و[فرع] → مخزن البيع الافتراضي.
     *
     * @return array{0: array<int, array<int, int>>, 1: array<int, int>}
     */
    private function salesWarehouses(): array
    {
        $deptMap = [];
        $defaults = [];
        foreach (Warehouse::where('type', Warehouse::TYPE_STANDARD)->get(['id', 'branch_id', 'department_id', 'role', 'is_default_sales']) as $w) {
            // مبيعات القسم (الوجبات) تُنسب لمخزن القسم نفسه، لا لمخازن المصنع أو المعرض.
            if ($w->department_id && ($w->role ?? 'department') === 'department') {
                $deptMap[$w->branch_id][$w->department_id] = $w->id;
            }
            if ($w->is_default_sales) {
                $defaults[$w->branch_id] = $w->id;
            }
        }

        return [$deptMap, $defaults];
    }

    /**
     * تكلفة الوحدة الحالية لكل "مخزن:صنف": متوسط المخزن، وإلا المتوسط عبر المخازن أو آخر تكلفة شراء.
     *
     * @param  array<int, string>  $wiKeys
     * @return array<string, string>
     */
    private function unitCosts(array $wiKeys): array
    {
        if ($wiKeys === []) {
            return [];
        }
        $itemIds = array_values(array_unique(array_map(fn ($k) => (int) explode(':', $k)[1], $wiKeys)));
        $avg = DB::table('stock_balances')->whereIn('stock_item_id', $itemIds)->where('avg_cost', '>', 0)
            ->get(['warehouse_id', 'stock_item_id', 'avg_cost'])
            ->mapWithKeys(fn ($r) => ["{$r->warehouse_id}:{$r->stock_item_id}" => (string) $r->avg_cost]);
        $fallback = $this->recipes->currentUnitCosts($itemIds);

        $costs = [];
        foreach ($wiKeys as $k) {
            $costs[$k] = $avg[$k] ?? $fallback[(int) explode(':', $k)[1]] ?? '0';
        }

        return $costs;
    }

    /**
     * اسم الصنف ووحدته الأساسية ووحدة العرض: وحدة الشراء إن حُددت، وإلا أصغر وحدة بديلة أكبر من الأساسية
     * (كغ بدل غرام) — للتبديل بين الوحدتين في الواجهة.
     *
     * @param  array<int>  $ids
     * @return array<int, array{name:string, code:?string, base_unit:?string, display_unit:?array{name:string, factor:float}}>
     */
    private function itemMeta(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return StockItem::with(['baseUnit:id,name', 'units.unit:id,name'])->whereIn('id', $ids)->get()
            ->mapWithKeys(function (StockItem $i) {
                $alt = $i->purchase_unit_id ? $i->units->firstWhere('unit_id', $i->purchase_unit_id) : null;
                $alt ??= $i->units->filter(fn ($u) => (float) $u->factor > 1)->sortBy(fn ($u) => (float) $u->factor)->first();

                return [$i->id => [
                    'name' => $i->name,
                    'code' => $i->code,
                    'base_unit' => $i->baseUnit?->name,
                    'display_unit' => $alt ? ['name' => $alt->unit?->name ?? '', 'factor' => (float) $alt->factor] : null,
                ]];
            })->all();
    }

    /**
     * @param  array<int>  $itemIds
     * @return array<int, string> آخر يوم جرد مرحّل (≤ نهاية الفترة) لكل صنف في المخازن المختارة.
     */
    private function lastCountDates(array $f, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return DB::table('stock_count_lines as l')
            ->join('stock_counts as c', 'c.id', '=', 'l.stock_count_id')
            ->where('c.status', 'posted')
            ->whereNotNull('l.counted_quantity')
            ->where('c.count_date', '<=', $f['to'])
            ->whereIn('l.stock_item_id', $itemIds)
            ->when($f['warehouses'] !== null, fn ($q) => $q->whereIn('c.warehouse_id', $f['warehouses'] ?: [0]))
            ->groupBy('l.stock_item_id')
            ->selectRaw('l.stock_item_id, MAX(c.count_date) as d')
            ->pluck('d', 'stock_item_id')
            ->map(fn ($d) => (string) $d)
            ->all();
    }

    private function bookVersion(string $owner, string $day): ?Recipe
    {
        if ($this->book === null) {
            $this->book = [];
            foreach (Recipe::with('lines')->orderBy('effective_from')->get() as $r) {
                $this->book[$r->item_id ? "p:{$r->item_id}" : "s:{$r->stock_item_id}"][] = $r;
            }
        }
        $found = null;
        foreach ($this->book[$owner] ?? [] as $r) {
            if ($r->effective_from->toDateString() > $day) {
                break;
            }
            $found = $r;
        }

        return $found;
    }

    /** @return array<int, string> */
    private function explodeCached(Recipe $recipe, string $day): array
    {
        return $this->explodeCache["{$recipe->id}|{$day}"] ??= $this->recipes->explode(
            $recipe, $day, 0, [], fn (int $id, string $d) => $this->bookVersion("s:{$id}", $d),
        );
    }

    /** @return array<int, string> */
    private function soldByProduct(array $sold): array
    {
        $out = [];
        foreach ($sold as $key => $qty) {
            $p = explode('|', $key)[1];
            $out[$p] = $this->add($out[$p] ?? '0', $qty);
        }

        return $out;
    }

    /** @return array<int, string> */
    private function wiKeys(array $contrib): array
    {
        return array_values(array_unique(array_map(function ($k) {
            [$w, , $i] = explode('|', $k);

            return "{$w}:{$i}";
        }, array_keys($contrib))));
    }

    /** @return array<int, string> */
    private function dayList(array $f): array
    {
        $days = [];
        for ($d = CarbonImmutable::parse($f['from']); $d->toDateString() <= $f['to']; $d = $d->addDay()) {
            $days[] = $d->toDateString();
        }

        return $days;
    }

    private function add(string $a, string $b): string
    {
        return bcadd($a, $b, self::S);
    }

    /** رقم للعرض: 6 خانات (دقة الأعمدة)، أو خانتان للمبالغ. */
    private function out(string $v, int $scale = 6): string
    {
        return $scale >= 6 ? UnitConverter::round($v) : number_format(round((float) $v, $scale), $scale, '.', '');
    }
}
