<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\Warehouse;
use App\Services\Inventory\AccountingDay;
use App\Services\Inventory\CostVisibility;
use App\Services\Inventory\WarehouseAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * "أرصدة المخازن في تاريخ" — من دفتر الحركات لا من الرصيد الحالي، فيصح لأي يوم محاسبي سابق.
 *
 *   الرصيد في تاريخ = Σ كميات الحركات حتى اليوم المحاسبي `to` (شاملاً)
 *   أول الفترة      = Σ الكميات قبل `from`؛ الوارد/الصادر = الحركات الموجبة/السالبة بين from وto
 *   التقييم avg     = Σ قيم الحركات حتى `to` (القيمة الجارية بالمتوسط المرجّح كما في كرت الصنف)
 *   التقييم last    = الكمية × سعر آخر استلام مشتريات للصنف حتى `to` (وإلا المتوسط إن لم يُشترَ)
 *
 * أنواع التقرير: index (فهرس الأصناف: صنف واحد لكل سطر)، category (إحصائية حسب التصنيف)،
 * count_sheet (كشف الجرد: مخزن × صنف مع خانة للمعدود)، matrix (الكميات حسب المخازن: صنف × مخزن).
 * تجميع واحد في قاعدة البيانات حسب المخزن والصنف، ومقيّد بمخازن المستخدم.
 */
class StockReportController extends InventoryController
{
    public const REPORTS = ['index', 'category', 'count_sheet', 'matrix'];

    public function __construct(private WarehouseAccess $access) {}

    /** GET /api/inventory/reports/balances?to=&from=&warehouse_ids[]=&category_id=&status=&code_from=&code_to=&zeros=&valuation=&report= */
    public function balances(Request $request): JsonResponse
    {
        $data = $this->validateAr($request, [
            'to' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['integer', 'exists:warehouses,id'],
            'category_id' => ['nullable', 'integer', 'exists:stock_categories,id'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
            'code_from' => ['nullable', 'string', 'max:64'],
            'code_to' => ['nullable', 'string', 'max:64'],
            'zeros' => ['nullable', 'boolean'],
            'valuation' => ['nullable', Rule::in(['avg', 'last'])],
            'report' => ['nullable', Rule::in(self::REPORTS)],
        ]);
        $user = $request->user();
        $to = $data['to'] ?? AccountingDay::fromConfig()->of(now());
        $from = $data['from'] ?? null;
        if ($from && $from > $to) {
            return $this->error('"من" يجب أن يسبق "إلى".', 422);
        }
        $report = $data['report'] ?? 'index';
        $valuation = $data['valuation'] ?? 'avg';
        $showCosts = CostVisibility::canView($user);

        // المخازن: المختارة ضمن ما يراه المستخدم، وإلا كل مخازنه.
        $visible = $this->access->scope(Warehouse::query(), $user)->orderBy('name')->get(['id', 'name', 'code']);
        $warehouses = empty($data['warehouse_ids']) ? $visible : $visible->whereIn('id', array_map('intval', $data['warehouse_ids']))->values();
        if (! empty($data['warehouse_ids']) && $warehouses->count() !== count(array_unique($data['warehouse_ids']))) {
            return $this->error('لا تملك صلاحية الوصول لأحد المخازن المختارة.', 403);
        }

        // الأصناف المطابقة للفلاتر (الجدول صغير نسبياً؛ التجميع الثقيل على الحركات في SQL).
        $categoryIds = isset($data['category_id']) ? $this->withDescendants((int) $data['category_id']) : null;
        $items = StockItem::query()
            ->with(['baseUnit:id,name', 'categories:id,name'])
            ->where('is_stored', true)
            ->when(($data['status'] ?? 'active') !== 'all', fn ($q) => $q->where('is_active', ($data['status'] ?? 'active') === 'active'))
            ->when($categoryIds, fn ($q) => $q->whereHas('categories', fn ($c) => $c->whereIn('stock_categories.id', $categoryIds)))
            ->when($data['code_from'] ?? null, fn ($q, $v) => $q->where('code', '>=', $v))
            ->when($data['code_to'] ?? null, fn ($q, $v) => $q->where('code', '<=', $v))
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'base_unit_id', 'safety_stock', 'is_active', 'last_cost'])
            ->keyBy('id');

        $agg = $this->aggregate($warehouses->pluck('id')->all(), $items->keys()->all(), $from, $to);
        $lastPrices = $valuation === 'last' ? $this->lastPurchasePrices($items->keys()->all(), $to) : collect();
        $zeros = (bool) ($data['zeros'] ?? false);

        // صف لكل (مخزن، صنف) فيه حركة؛ والأصفار عند الطلب تكمل الأصناف بلا حركة.
        $cells = [];
        foreach ($agg as $r) {
            $cells[$r->warehouse_id][$r->stock_item_id] = $r;
        }

        // [سعر الوحدة، القيمة]: المتوسط = قيمة الحركات نفسها (لا كمية × سعر مقرّب)، وآخر شراء = الكمية × سعره.
        $unitValue = function (StockItem $item, string $qty, string $value) use ($valuation, $lastPrices): array {
            if ($valuation === 'last' && isset($lastPrices[$item->id])) {
                $price = (string) $lastPrices[$item->id];

                return [$price, bcmul($qty, $price, 6)];
            }

            return [bccomp($qty, '0', 6) !== 0 ? bcdiv($value, $qty, 6) : null, $value];
        };

        $line = function (StockItem $item, array $parts) use ($unitValue, $showCosts, $from): array {
            $qty = '0';
            $value = '0';
            $opening = '0';
            $in = '0';
            $out = '0';
            foreach ($parts as $p) {
                $qty = bcadd($qty, (string) $p->closing, 6);
                $value = bcadd($value, (string) $p->closing_value, 6);
                $opening = bcadd($opening, (string) $p->opening, 6);
                $in = bcadd($in, (string) $p->qty_in, 6);
                $out = bcadd($out, (string) $p->qty_out, 6);
            }
            [$unit, $total] = $unitValue($item, $qty, $value);

            return [
                'stock_item_id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'unit' => $item->baseUnit?->name,
                'is_active' => (bool) $item->is_active,
                'category' => $item->categories->sortBy('id')->first()?->name,
                'safety_stock' => (string) $item->safety_stock,
                'opening' => $from ? $opening : null,
                'quantity_in' => $from ? $in : null,
                'quantity_out' => $from ? $out : null,
                'quantity' => $qty,
                'unit_cost' => $showCosts ? $unit : null,
                'value' => $showCosts ? $total : null,
            ];
        };

        $hasMovement = fn ($parts) => collect($parts)->contains(fn ($p) => bccomp((string) $p->closing, '0', 6) !== 0
            || bccomp((string) $p->qty_in, '0', 6) !== 0 || bccomp((string) $p->qty_out, '0', 6) !== 0);

        $rows = [];
        $totals = ['quantity' => '0', 'value' => '0'];
        $whIds = $warehouses->pluck('id')->all();

        if ($report === 'count_sheet') {
            foreach ($warehouses as $w) {
                foreach ($items as $item) {
                    $part = $cells[$w->id][$item->id] ?? null;
                    if (! $zeros && (! $part || ! $hasMovement([$part]))) {
                        continue;
                    }
                    $row = $line($item, $part ? [$part] : []);
                    $rows[] = ['warehouse_id' => $w->id, 'warehouse' => $w->name, ...$row];
                    $totals['value'] = bcadd($totals['value'], (string) ($row['value'] ?? '0'), 6);
                }
            }
        } else {
            foreach ($items as $item) {
                $parts = array_values(array_filter(array_map(fn ($id) => $cells[$id][$item->id] ?? null, $whIds)));
                if (! $zeros && ! $hasMovement($parts)) {
                    continue;
                }
                $row = $line($item, $parts);
                if ($report === 'matrix') {
                    $row['by_warehouse'] = collect($whIds)->mapWithKeys(fn ($id) => [$id => (string) ($cells[$id][$item->id]->closing ?? '0')]);
                }
                $rows[] = $row;
                $totals['quantity'] = bcadd($totals['quantity'], $row['quantity'], 6);
                $totals['value'] = bcadd($totals['value'], (string) ($row['value'] ?? '0'), 6);
            }
        }

        if ($report === 'category') {
            $rows = collect($rows)->groupBy(fn ($r) => $r['category'] ?? 'بلا تصنيف')->map(fn (Collection $g, $name) => [
                'category' => $name,
                'items_count' => $g->count(),
                'quantity' => $g->reduce(fn ($c, $r) => bcadd($c, $r['quantity'], 6), '0'),
                'value' => $showCosts ? $g->reduce(fn ($c, $r) => bcadd($c, (string) ($r['value'] ?? '0'), 6), '0') : null,
            ])->sortBy('category')->values()->all();
        }

        return $this->success('تم', [
            'report' => $report,
            'valuation' => $valuation,
            'from' => $from,
            'to' => $to,
            'costs_visible' => $showCosts,
            'warehouses' => $warehouses->values(),
            'rows' => $rows,
            'totals' => ['rows' => count($rows), 'quantity' => $report === 'count_sheet' ? null : $totals['quantity'], 'value' => $showCosts ? $totals['value'] : null],
        ]);
    }

    /**
     * @param  array<int>  $warehouseIds
     * @param  array<int>  $itemIds
     * @return Collection<int, object>
     */
    private function aggregate(array $warehouseIds, array $itemIds, ?string $from, string $to): Collection
    {
        if ($warehouseIds === [] || $itemIds === []) {
            return collect();
        }
        $from ??= $to;

        return DB::table('stock_movements')
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('stock_item_id', $itemIds)
            ->where('accounting_day', '<=', $to)
            ->groupBy('warehouse_id', 'stock_item_id')
            ->select('warehouse_id', 'stock_item_id')
            ->selectRaw('SUM(quantity) as closing, SUM(total_cost) as closing_value')
            ->selectRaw('SUM(CASE WHEN accounting_day < ? THEN quantity ELSE 0 END) as opening', [$from])
            ->selectRaw('SUM(CASE WHEN accounting_day >= ? AND quantity > 0 THEN quantity ELSE 0 END) as qty_in', [$from])
            ->selectRaw('SUM(CASE WHEN accounting_day >= ? AND quantity < 0 THEN -quantity ELSE 0 END) as qty_out', [$from])
            ->get();
    }

    /**
     * سعر الوحدة الأساسية في آخر استلام مشتريات لكل صنف حتى اليوم (غير معكوس).
     *
     * @param  array<int>  $itemIds
     * @return Collection<int, string>
     */
    private function lastPurchasePrices(array $itemIds, string $to): Collection
    {
        if ($itemIds === []) {
            return collect();
        }
        $latest = DB::table('stock_movements as m')
            ->where('m.type', 'purchase')->whereNull('m.reversal_of_id')->where('m.quantity', '>', 0)
            ->whereNotExists(fn ($q) => $q->from('stock_movements as r')->whereColumn('r.reversal_of_id', 'm.id'))
            ->whereIn('m.stock_item_id', $itemIds)
            ->where('m.accounting_day', '<=', $to)
            ->groupBy('m.stock_item_id')
            ->selectRaw('MAX(m.id) as id');

        return DB::table('stock_movements')->whereIn('id', $latest)->pluck('unit_cost', 'stock_item_id');
    }

    /** @return array<int> */
    private function withDescendants(int $rootId): array
    {
        $children = StockCategory::query()->get(['id', 'parent_id'])->groupBy('parent_id');
        $ids = [];
        $queue = [$rootId];
        while ($queue !== []) {
            $id = array_shift($queue);
            if (in_array($id, $ids, true)) {
                continue;
            }
            $ids[] = $id;
            foreach ($children->get($id, collect()) as $child) {
                $queue[] = (int) $child->id;
            }
        }

        return $ids;
    }
}
