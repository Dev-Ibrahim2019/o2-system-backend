<?php

namespace App\Services\Inventory;

use App\Models\DepartmentBranchSetting;
use App\Models\InventorySetting;
use App\Models\Item;
use App\Models\StockCount;
use App\Models\StockDocument;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * منطق "O2 لإدارة الجودة والرقابة" فوق محرك المخزون: الإنتاجية اليومية والعجز، ومحضر الإتلاف،
 * وقائمة الدفعات وصلاحياتها، وقالب القسم في الفرع، والتقارير. كل الحسابات بالوحدة الأساسية.
 */
class QualityService
{
    public function __construct(private InventoryAnalyticsService $analytics, private ?AccountingDay $accountingDay = null)
    {
        $this->accountingDay ??= AccountingDay::fromConfig();
    }

    // ── الإنتاجية اليومية ─────────────────────────────────────────────────────

    /**
     * الإنتاجية اليومية لقسم (مخزن القسم أو مخزن مواد المصنع) في يوم:
     *   المستلمة   = رصيد بداية اليوم + الوارد (تحويل للقسم / استلام / إنتاج داخل)
     *   المتبقي    = جرد نهاية اليوم
     *   المستهلك   = المستلمة − المحوَّل للخارج − المتبقي − الإتلاف المسجّل
     *   المفترض    = Σ (المباع أو المنتج × كمية الصنف في الوصفة)
     *   العجز      = المستهلك − المفترض، ونسبته = العجز ÷ المفترض × 100
     * الإتلاف المسجّل لا يُحسب عجزاً. الوضع: أفضل/أسوأ/مستقر مقارنة بنسبة عجز الصنف في اليوم السابق.
     *
     * @return array<string, mixed>
     */
    public function productivity(Warehouse $warehouse, string $day): array
    {
        $today = $this->dayRows($warehouse, $day);
        $prev = $this->dayRows($warehouse, CarbonImmutable::parse($day)->subDay()->toDateString());
        $prevByItem = collect($prev['rows'])->keyBy('stock_item_id');

        $rows = array_map(function ($r) use ($prevByItem) {
            $p = $prevByItem->get($r['stock_item_id']);
            $r['previous_deficit_percent'] = $p['deficit_percent'] ?? null;
            $r['trend'] = self::trend($r['deficit_percent'], $p['deficit_percent'] ?? null);

            return $r;
        }, $today['rows']);

        $count = StockCount::where('warehouse_id', $warehouse->id)->where('kind', 'daily')->where('business_day', $day)->first(['id', 'number', 'status']);

        return [
            'day' => $day,
            'warehouse' => ['id' => $warehouse->id, 'name' => $warehouse->name],
            'count' => $count,
            'rows' => $rows,
            'totals' => [
                ...$today['totals'],
                'previous_deficit_percent' => $prev['totals']['deficit_percent'],
                'trend' => self::trend($today['totals']['deficit_percent'], $prev['totals']['deficit_percent']),
            ],
        ];
    }

    /** @return array{rows: array<int, array<string, mixed>>, totals: array<string, mixed>} */
    private function dayRows(Warehouse $warehouse, string $day): array
    {
        $variance = $this->analytics->variance([
            'from' => $day, 'to' => $day, 'days' => 1, 'warehouses' => [$warehouse->id], 'items' => null,
            'threshold' => (string) InventorySetting::current()->variance_alert_percent, 'stagnant_days' => 30, 'stock_item_id' => null,
        ]);

        $rows = [];
        $consumedValue = '0';
        $theoValue = '0';
        foreach ($variance['rows'] as $v) {
            $received = bcadd(bcadd($v['opening'], $v['inflow'], 6), $v['transfers_in'], 6);
            // المستهلك = الفعلي − الإتلاف (الفعلي في التحليلات يشمل الإتلاف)
            $consumed = bcsub($v['actual_qty'], $v['waste'], 6);
            $deficit = bcsub($consumed, $v['theoretical_qty'], 6);
            $unit = $this->unitValue($v);
            $pct = bccomp($v['theoretical_qty'], '0', 6) > 0 ? bcdiv(bcmul($deficit, '100', 8), $v['theoretical_qty'], 2) : null;
            $rows[] = [
                'stock_item_id' => $v['stock_item_id'],
                'name' => $v['name'],
                'code' => $v['code'],
                'base_unit' => $v['base_unit'],
                'display_unit' => $v['display_unit'],
                'opening' => $v['opening'],
                'received' => $received,
                'transferred_out' => $v['transfers_out'],
                'remaining' => $v['closing'],
                'waste' => $v['waste'],
                'consumed' => $consumed,
                'theoretical' => $v['theoretical_qty'],
                'deficit' => $deficit,
                'deficit_value' => number_format((float) bcmul($deficit, $unit, 6), 2, '.', ''),
                'deficit_percent' => $pct,
                'alert' => $pct !== null && bccomp($pct, (string) InventorySetting::current()->variance_alert_percent, 2) > 0,
            ];
            $consumedValue = bcadd($consumedValue, bcmul($consumed, $unit, 6), 6);
            $theoValue = bcadd($theoValue, bcmul($v['theoretical_qty'], $unit, 6), 6);
        }
        usort($rows, fn ($a, $b) => (float) $b['deficit_value'] <=> (float) $a['deficit_value']);
        $deficitValue = bcsub($consumedValue, $theoValue, 6);

        return [
            'rows' => $rows,
            'totals' => [
                'consumed_value' => number_format((float) $consumedValue, 2, '.', ''),
                'theoretical_value' => number_format((float) $theoValue, 2, '.', ''),
                'deficit_value' => number_format((float) $deficitValue, 2, '.', ''),
                'deficit_percent' => bccomp($theoValue, '0', 6) > 0 ? bcdiv(bcmul($deficitValue, '100', 8), $theoValue, 2) : null,
            ],
        ];
    }

    /** أفضل (نسبة العجز أقل) / أسوأ (أعلى) / مستقر (فرق أقل من نصف نقطة). */
    private static function trend(?string $now, ?string $before): ?string
    {
        if ($now === null || $before === null) {
            return null;
        }
        $diff = (float) $now - (float) $before;

        return abs($diff) < 0.5 ? 'stable' : ($diff < 0 ? 'better' : 'worse');
    }

    /** تكلفة وحدة الصنف من صف التحليلات (قيمة ÷ كمية)، وإلا صفر. */
    private function unitValue(array $v): string
    {
        foreach ([['theoretical_value', 'theoretical_qty'], ['actual_value', 'actual_qty']] as [$val, $qty]) {
            if ($v[$val] !== null && bccomp((string) $v[$qty], '0', 6) !== 0) {
                return bcdiv((string) $v[$val], (string) $v[$qty], 8);
            }
        }

        return '0';
    }

    // ── محضر الإتلاف ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function wasteMinutes(Warehouse $warehouse, string $day): array
    {
        $warehouse->loadMissing(['branch:id,name', 'department:id,name,nameAr']);
        $docs = StockDocument::with(['lines.stockItem:id,code,name,base_unit_id', 'lines.stockItem.baseUnit:id,name', 'lines.unit:id,name', 'lines.batch:id,code', 'poster:id,name'])
            ->where('type', StockDocument::TYPE_WASTE)->where('status', StockDocument::STATUS_POSTED)
            ->where('warehouse_id', $warehouse->id)->where('document_date', $day)
            ->orderBy('id')->get();

        $rows = [];
        $total = '0';
        foreach ($docs as $d) {
            foreach ($d->lines as $l) {
                $rows[] = [
                    'document' => $d->number,
                    'item' => $l->stockItem?->name,
                    'code' => $l->stockItem?->code,
                    'batch' => $l->batch?->code,
                    'quantity' => (string) $l->quantity,
                    'unit' => $l->unit?->name,
                    'reason' => StockDocument::WASTE_REASONS[$l->waste_reason] ?? $l->waste_reason,
                    'value' => (string) $l->posted_cost,
                    'posted_by' => $d->poster?->name,
                ];
                $total = bcadd($total, (string) $l->posted_cost, 6);
            }
        }

        return [
            'day' => $day,
            'branch' => $warehouse->branch?->name,
            'department' => $warehouse->department?->nameAr ?: $warehouse->department?->name,
            'warehouse' => $warehouse->name,
            'rows' => $rows,
            'total_value' => number_format((float) $total, 2, '.', ''),
        ];
    }

    // ── الدفعات ───────────────────────────────────────────────────────────────

    /**
     * أرصدة الدفعات: المتبقي، ومدة التخزين (من وصولها للمخزن)، والمدة المتبقية للصلاحية وحالتها،
     * وتجاوز مدة العرض في المعارض.
     *
     * @param  array<int>|null  $warehouseIds
     * @return array<int, array<string, mixed>>
     */
    public function batches(?array $warehouseIds, ?int $stockItemId, bool $availableOnly, ?string $search = null): array
    {
        $today = $this->accountingDay->of(now());
        $settings = InventorySetting::current();

        return DB::table('stock_batch_balances as bb')
            ->join('stock_batches as b', 'b.id', '=', 'bb.batch_id')
            ->join('stock_items as i', 'i.id', '=', 'b.stock_item_id')
            ->join('stock_units as u', 'u.id', '=', 'i.base_unit_id')
            ->join('warehouses as w', 'w.id', '=', 'bb.warehouse_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'b.supplier_id')
            ->when($warehouseIds !== null, fn ($q) => $q->whereIn('bb.warehouse_id', $warehouseIds ?: [0]))
            ->when($stockItemId, fn ($q) => $q->where('b.stock_item_id', $stockItemId))
            ->when($availableOnly, fn ($q) => $q->where('bb.quantity', '>', 0))
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('b.code', 'like', "%{$search}%")->orWhere('i.name', 'like', "%{$search}%")))
            ->orderByRaw('b.expiry_date IS NULL, b.expiry_date')
            ->orderBy('b.id')
            ->limit(500)
            ->get(['b.id', 'b.code', 'b.source', 'b.stock_item_id', 'i.name as item', 'i.code as item_code', 'u.name as base_unit',
                'bb.warehouse_id', 'w.name as warehouse', 'w.role as warehouse_role', 's.name as supplier', 'b.unit_cost', 'b.quantity as original',
                'bb.quantity as remaining', 'b.cartons', 'b.production_date', 'b.expiry_date', 'bb.arrived_at', 'b.notes'])
            ->map(function ($r) use ($today, $settings) {
                $storage = $r->arrived_at
                    ? (int) CarbonImmutable::parse($this->accountingDay->of(CarbonImmutable::parse($r->arrived_at, 'UTC')))->diffInDays(CarbonImmutable::parse($today))
                    : null;
                $expiry = BatchService::expiryStatus($r->expiry_date ? substr((string) $r->expiry_date, 0, 10) : null, $today);

                return [
                    'id' => (int) $r->id, 'code' => $r->code, 'source' => $r->source,
                    'stock_item_id' => (int) $r->stock_item_id, 'item' => $r->item, 'item_code' => $r->item_code, 'base_unit' => $r->base_unit,
                    'warehouse_id' => (int) $r->warehouse_id, 'warehouse' => $r->warehouse, 'supplier' => $r->supplier,
                    'unit_cost' => (string) $r->unit_cost, 'original' => (string) $r->original, 'remaining' => (string) $r->remaining,
                    'cartons' => $r->cartons, 'production_date' => $r->production_date ? substr((string) $r->production_date, 0, 10) : null,
                    'expiry_date' => $r->expiry_date ? substr((string) $r->expiry_date, 0, 10) : null, 'notes' => $r->notes,
                    'storage_days' => $storage,
                    ...$expiry,
                    // المعرض: تنبيه إذا تجاوزت مدة التخزين الحد (إعداد، الافتراضي 3 أيام)
                    'showroom_overdue' => $r->warehouse_role === 'showroom' && $storage !== null && $storage > $settings->showroom_max_days,
                ];
            })->all();
    }

    // ── قالب القسم في الفرع ──────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> كل قسم في كل فرع يعمل فيه، مع إعداده ومخازنه حسب الدور. */
    public function departmentSettings(): array
    {
        $pairs = DB::table('branch_department as bd')
            ->join('departments as d', 'd.id', '=', 'bd.department_id')
            ->join('branches as b', 'b.id', '=', 'bd.branch_id')
            ->whereNull('d.deleted_at')
            ->orderBy('b.id')->orderBy('d.id')
            ->get(['bd.department_id', 'bd.branch_id', 'd.name as department', 'd.nameAr', 'b.name as branch']);
        $settings = DepartmentBranchSetting::all()->keyBy(fn ($s) => $s->department_id.':'.$s->branch_id);
        $warehouses = Warehouse::whereNotNull('department_id')->get(['id', 'name', 'branch_id', 'department_id', 'role'])
            ->groupBy(fn ($w) => $w->department_id.':'.$w->branch_id);

        return $pairs->map(function ($p) use ($settings, $warehouses) {
            $key = $p->department_id.':'.$p->branch_id;
            $s = $settings->get($key);

            return [
                'department_id' => (int) $p->department_id, 'branch_id' => (int) $p->branch_id,
                'department' => $p->nameAr ?: $p->department, 'branch' => $p->branch,
                'has_meals' => $s?->has_meals ?? true, 'has_factory' => $s?->has_factory ?? false,
                'has_showroom' => $s?->has_showroom ?? false, 'is_shawarma' => $s?->is_shawarma ?? false,
                'meat_category_id' => $s?->meat_category_id, 'spice_item_id' => $s?->spice_item_id, 'skewer_item_id' => $s?->skewer_item_id,
                'warehouses' => ($warehouses->get($key) ?? collect())->map(fn ($w) => ['id' => $w->id, 'name' => $w->name, 'role' => $w->role])->values(),
            ];
        })->all();
    }

    /** @param  array<string, mixed>  $data */
    public function saveDepartmentSetting(int $departmentId, int $branchId, array $data): DepartmentBranchSetting
    {
        if ($data['is_shawarma'] ?? false) {
            if (empty($data['meat_category_id']) || empty($data['skewer_item_id'])) {
                throw new InventoryRuleException('قسم الشاورما يحتاج تصنيف اللحوم وصنف السيخ.');
            }
        }

        return DepartmentBranchSetting::updateOrCreate(
            ['department_id' => $departmentId, 'branch_id' => $branchId],
            collect($data)->only(['has_meals', 'has_factory', 'has_showroom', 'is_shawarma', 'meat_category_id', 'spice_item_id', 'skewer_item_id'])->all(),
        );
    }

    // ── التقارير ──────────────────────────────────────────────────────────────

    /**
     * المخزن الرئيسي: الوارد والصادر، أعلى الأصناف توريداً، الاستهلاك حسب الأصناف والأقسام، نسبة
     * الإتلاف، أكبر مورّد، فواتير الصرف غير المسلّمة، والراكد وأيام التغطية (من التحليلات).
     *
     * @param  array<string, mixed>  $f  فلاتر التحليلات (مخزن واحد)
     * @return array<string, mixed>
     */
    public function mainWarehouseReport(array $f, Warehouse $warehouse): array
    {
        $docs = fn (string $type) => DB::table('stock_documents as d')->join('stock_document_lines as l', 'l.stock_document_id', '=', 'd.id')
            ->join('stock_items as i', 'i.id', '=', 'l.stock_item_id')
            ->where('d.type', $type)->where('d.status', 'posted')->where('d.warehouse_id', $warehouse->id)
            ->whereBetween('d.document_date', [$f['from'], $f['to']]);

        $inValue = (string) $docs('receipt')->sum('l.posted_cost');
        $outValue = (string) $docs('transfer')->sum('l.posted_cost');
        $wasteValue = (string) $docs('waste')->sum('l.posted_cost');
        $overview = $this->analytics->overview($f);

        return [
            'total_in_value' => number_format((float) $inValue, 2, '.', ''),
            'total_out_value' => number_format((float) $outValue, 2, '.', ''),
            'waste_value' => number_format((float) $wasteValue, 2, '.', ''),
            // نسبة الإتلاف = قيمة الإتلاف ÷ (الصادر + الإتلاف) × 100
            'waste_percent' => bccomp(bcadd($outValue, $wasteValue, 6), '0', 6) > 0
                ? bcdiv(bcmul($wasteValue, '100', 8), bcadd($outValue, $wasteValue, 6), 2) : null,
            'top_supplied' => $docs('receipt')->groupBy('i.id', 'i.name')
                ->selectRaw('i.id, i.name, ROUND(SUM(l.posted_cost), 2) as value')->orderByDesc('value')->limit(10)->get(),
            'consumption_by_item' => $docs('transfer')->groupBy('i.id', 'i.name')
                ->selectRaw('i.id, i.name, ROUND(SUM(l.posted_cost), 2) as value')->orderByDesc('value')->get(),
            'consumption_by_department' => $docs('transfer')->join('warehouses as tw', 'tw.id', '=', 'd.to_warehouse_id')
                ->groupBy('tw.id', 'tw.name')->selectRaw('tw.id, tw.name, ROUND(SUM(l.posted_cost), 2) as value')->orderByDesc('value')->get(),
            'top_supplier' => DB::table('stock_documents as d')->join('suppliers as s', 's.id', '=', 'd.supplier_id')
                ->where('d.type', 'receipt')->where('d.status', 'posted')->where('d.warehouse_id', $warehouse->id)
                ->whereBetween('d.document_date', [$f['from'], $f['to']])
                ->groupBy('s.id', 's.name')->selectRaw('s.id, s.name, ROUND(SUM(d.total_cost), 2) as value, COUNT(*) as documents')
                ->orderByDesc('value')->limit(5)->get(),
            'undelivered' => StockDocument::with('toWarehouse:id,name')->where('type', 'transfer')->where('status', 'posted')
                ->where('warehouse_id', $warehouse->id)->where('delivery_status', 'undelivered')
                ->whereBetween('document_date', [$f['from'], $f['to']])
                ->get(['id', 'number', 'document_date', 'to_warehouse_id', 'destination', 'total_cost']),
            'stagnant' => $overview['stagnant'],
            'coverage_days' => $overview['kpis']['coverage_days'],
        ];
    }

    /**
     * القسم: الاستهلاك والمفترض والعجز بالتفصيل، المبيعات (من نقطة البيع)، نسبة الهدر، وأعلى/أقل
     * الأصناف هدراً مع الوجبات التي تدخل فيها.
     *
     * @return array<string, mixed>
     */
    public function departmentReport(array $f, Warehouse $warehouse): array
    {
        $variance = $this->analytics->variance($f);
        $rows = array_map(function ($v) {
            $consumed = bcsub($v['actual_qty'], $v['waste'], 6);
            $deficit = bcsub($consumed, $v['theoretical_qty'], 6);

            return [
                'stock_item_id' => $v['stock_item_id'], 'name' => $v['name'], 'base_unit' => $v['base_unit'], 'display_unit' => $v['display_unit'],
                'consumed' => $consumed, 'theoretical' => $v['theoretical_qty'], 'deficit' => $deficit,
                'deficit_percent' => bccomp($v['theoretical_qty'], '0', 6) > 0 ? bcdiv(bcmul($deficit, '100', 8), $v['theoretical_qty'], 2) : null,
                'waste' => $v['waste'],
                // نسبة الهدر = الإتلاف ÷ (المستهلك + الإتلاف) × 100
                'waste_percent' => bccomp($v['actual_qty'], '0', 6) > 0 ? bcdiv(bcmul($v['waste'], '100', 8), $v['actual_qty'], 2) : null,
            ];
        }, $variance['rows']);

        [$start] = $this->accountingDay->bounds($f['from']);
        [, $end] = $this->accountingDay->bounds($f['to']);
        $sales = DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.payment_status', 'paid')->whereNull('o.deleted_at')->where('oi.status', '!=', 'cancelled')
            ->where('o.branch_id', $warehouse->branch_id)->where('oi.department_id', $warehouse->department_id)
            ->where('o.paid_at', '>=', $start->toDateTimeString())->where('o.paid_at', '<', $end->toDateTimeString())
            ->selectRaw('COALESCE(SUM(oi.total), 0) as revenue, COALESCE(SUM(oi.quantity), 0) as quantity')->first();

        $wasted = collect($rows)->filter(fn ($r) => (float) $r['waste'] > 0)->sortByDesc(fn ($r) => (float) $r['waste'])->values();
        $mealsFor = fn (int $itemId) => Item::whereIn('id', DB::table('recipes as r')->join('recipe_lines as rl', 'rl.recipe_id', '=', 'r.id')
            ->where('rl.stock_item_id', $itemId)->whereNotNull('r.item_id')->pluck('r.item_id'))->pluck('name_ar')->filter()->values();

        return [
            'rows' => $rows,
            'totals' => $variance['totals'],
            'sales' => ['revenue' => number_format((float) $sales->revenue, 2, '.', ''), 'quantity' => (string) $sales->quantity],
            'most_wasted' => $wasted->take(5)->map(fn ($r) => [...$r, 'meals' => $mealsFor($r['stock_item_id'])])->values(),
            'least_wasted' => $wasted->reverse()->take(5)->map(fn ($r) => [...$r, 'meals' => $mealsFor($r['stock_item_id'])])->values(),
        ];
    }

    /**
     * المصنع والمعرض لقسم في فرع: الإنتاج وتكلفة الكيلو، قيمة الهدر، الأعلى والأقل مبيعاً في المعرض،
     * وأطول مدة تخزين في المعرض.
     *
     * @return array<string, mixed>
     */
    public function factoryReport(array $f, int $departmentId, int $branchId): array
    {
        $wh = Warehouse::where('department_id', $departmentId)->where('branch_id', $branchId)->get(['id', 'role'])->groupBy('role');
        $ids = fn (string $role) => ($wh->get($role) ?? collect())->pluck('id')->all() ?: [0];

        $production = DB::table('stock_documents as d')->join('stock_document_lines as l', 'l.stock_document_id', '=', 'd.id')
            ->join('stock_batches as b', 'b.id', '=', 'l.created_batch_id')->join('stock_items as i', 'i.id', '=', 'l.stock_item_id')
            ->where('d.type', 'production')->where('d.status', 'posted')->whereIn('d.to_warehouse_id', $ids('factory_products'))
            ->whereBetween('d.document_date', [$f['from'], $f['to']])
            ->groupBy('i.id', 'i.name')
            // تكلفة الكيلو = تكلفة الإنتاج ÷ الوزن المنتج (كغ)
            ->selectRaw('i.id, i.name, SUM(b.quantity) as weight, ROUND(SUM(l.posted_cost), 2) as cost, ROUND(SUM(l.posted_cost) / (SUM(b.quantity) / 1000), 2) as cost_per_kg')
            ->orderByDesc('weight')->get();

        $wasteValue = (string) DB::table('stock_documents')->where('type', 'waste')->where('status', 'posted')
            ->whereIn('warehouse_id', array_merge($ids('factory_raw'), $ids('factory_products'), $ids('showroom')))
            ->whereBetween('document_date', [$f['from'], $f['to']])->sum('total_cost');

        $sales = DB::table('stock_documents as d')->join('stock_document_lines as l', 'l.stock_document_id', '=', 'd.id')
            ->join('stock_items as i', 'i.id', '=', 'l.stock_item_id')
            ->where('d.type', 'showroom_sale')->where('d.status', 'posted')->whereIn('d.warehouse_id', $ids('showroom'))
            ->whereBetween('d.document_date', [$f['from'], $f['to']])
            ->groupBy('i.id', 'i.name')
            ->selectRaw('i.id, i.name, SUM(l.quantity) as quantity, ROUND(SUM(l.quantity * l.sale_price), 2) as revenue')
            ->orderByDesc('revenue')->get();

        $storage = collect($this->batches($ids('showroom'), null, true))->sortByDesc('storage_days')->take(10)->values();

        return [
            'production' => $production,
            'total_production_weight' => (string) $production->sum('weight'),
            'waste_value' => number_format((float) $wasteValue, 2, '.', ''),
            'top_sold' => $sales->take(5)->values(),
            'least_sold' => $sales->reverse()->take(5)->values(),
            'longest_storage' => $storage,
        ];
    }
}
