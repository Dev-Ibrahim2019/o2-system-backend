<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Models\StockCount;
use App\Models\Warehouse;
use App\Services\Inventory\AccountingDay;
use App\Services\Inventory\CostVisibility;
use App\Services\Inventory\StockCountService;
use App\Services\Inventory\WarehouseAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * أوراق الجرد الفعلي. الوصول بحسب المخزن، والإنشاء/الإدخال/التثبيت لكلٍّ صلاحيته.
 * "تاريخ الحركة" للورقة = يوم التثبيت، وللمسودة = اليوم المحاسبي لإنشائها.
 */
class StockCountController extends InventoryController
{
    public function __construct(private StockCountService $counts, private WarehouseAccess $access) {}

    /** GET /api/inventory/counts?from=&to=&warehouse_id=&status=&search=&page=&per_page= */
    public function index(Request $request): JsonResponse
    {
        $data = $this->validateAr($request, [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $user = $request->user();
        $day = AccountingDay::fromConfig();
        $from = $data['from'] ?? null;
        $to = $data['to'] ?? null;
        $search = trim((string) ($data['search'] ?? ''));

        $query = StockCount::query()
            ->when(! $this->access->hasGlobalAccess($user), fn ($q) => $q->whereIn('warehouse_id', $this->access->assignedIds($user)))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->integer('warehouse_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', "%{$search}%")->orWhere('notes', 'like', "%{$search}%")))
            // المثبّت بيوم تثبيته، والمسودة باليوم المحاسبي لإنشائها (حدود اليوم بتوقيت UTC).
            ->when($from || $to, fn ($q) => $q->where(fn ($w) => $w
                ->where(fn ($p) => $p->whereIn('status', ['posted', 'reversed'])
                    ->when($from, fn ($x) => $x->where('count_date', '>=', $from))
                    ->when($to, fn ($x) => $x->where('count_date', '<=', $to)))
                ->orWhere(fn ($d) => $d->where('status', 'draft')
                    ->when($from, fn ($x) => $x->where('created_at', '>=', $day->bounds($from)[0]))
                    ->when($to, fn ($x) => $x->where('created_at', '<', $day->bounds($to)[1])))));

        $showCosts = CostVisibility::canView($user);
        $totals = (clone $query)->selectRaw('COUNT(*) as count, COALESCE(SUM(variance_value), 0) as variance_value')->toBase()->first();

        $page = $query
            ->with(['warehouse:id,name,code', 'creator:id,name', 'poster:id,name'])
            ->withCount(['lines', 'lines as counted_lines_count' => fn ($q) => $q->whereNotNull('counted_quantity')])
            ->orderByRaw("status = 'draft' desc")
            ->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 25), 1), 200));

        $page->getCollection()->transform(function (StockCount $c) use ($showCosts, $day) {
            $row = [...$c->toArray(), 'day' => $c->count_date?->toDateString() ?? $day->of($c->created_at)];

            return $showCosts ? $row : CostVisibility::strip($row, ['variance_value']);
        });

        return $this->success('تم', [
            ...$page->toArray(),
            'summary' => ['count' => (int) $totals->count, 'variance_value' => $showCosts ? (string) $totals->variance_value : null],
        ]);
    }

    /** GET /api/inventory/counts/{count} — الورقة بأسطرها ووحدات كل صنف للإدخال. */
    public function show(Request $request, StockCount $count): JsonResponse
    {
        if ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($count->warehouse_id))) {
            return $denied;
        }

        return $this->success('تم', $this->present($request, $count));
    }

    /** POST /api/inventory/counts {warehouse_id, category_id?, notes?} */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateAr($request, [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'category_id' => ['nullable', 'integer', 'exists:stock_categories,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $warehouse = Warehouse::findOrFail($data['warehouse_id']);
        if ($denied = $this->denyUnlessCanAccess($request, $warehouse)) {
            return $denied;
        }

        return $this->attempt(fn () => $this->success(
            'تم إنشاء ورقة الجرد',
            $this->present($request, $this->counts->create($warehouse, $data['category_id'] ?? null, $data['notes'] ?? null, $request->user())),
            201,
        ));
    }

    /** PUT /api/inventory/counts/{count} {notes?, lines:[{stock_item_id, unit_id?, entered_quantity|null}]} */
    public function update(Request $request, StockCount $count): JsonResponse
    {
        $data = $this->validateAr($request, [
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['present', 'array'],
            'lines.*.stock_item_id' => ['required', 'integer', 'distinct'],
            'lines.*.unit_id' => ['nullable', 'integer', 'exists:stock_units,id'],
            'lines.*.entered_quantity' => ['nullable', 'numeric', 'min:0'],
        ]);
        if ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($count->warehouse_id))) {
            return $denied;
        }

        return $this->attempt(fn () => $this->success(
            'تم حفظ المعدود',
            $this->present($request, $this->counts->saveCounts($count, $data['lines'], $data['notes'] ?? null)),
        ));
    }

    /** DELETE /api/inventory/counts/{count} — للمسودة فقط. */
    public function destroy(Request $request, StockCount $count): JsonResponse
    {
        if ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($count->warehouse_id))) {
            return $denied;
        }

        return $this->attempt(function () use ($count) {
            $this->counts->delete($count);

            return $this->success('تم حذف ورقة الجرد');
        });
    }

    /** POST /api/inventory/counts/{count}/post — تثبيت الجرد (تسوية الفروق). */
    public function post(Request $request, StockCount $count): JsonResponse
    {
        if ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($count->warehouse_id))) {
            return $denied;
        }

        return $this->attempt(fn () => $this->success(
            'تم تثبيت الجرد وتسوية الفروق',
            $this->present($request, $this->counts->post($count, $request->user())),
        ));
    }

    /** POST /api/inventory/counts/{count}/reverse {reason} — عكس جرد معتمد (تُعكس تسوياته في الكاردكس). */
    public function reverse(Request $request, StockCount $count): JsonResponse
    {
        $data = $this->validateAr($request, ['reason' => ['required', 'string', 'max:300']]);
        if ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($count->warehouse_id))) {
            return $denied;
        }

        return $this->attempt(fn () => $this->success(
            'تم عكس الجرد وتسوياته',
            $this->present($request, $this->counts->reverse($count, $data['reason'], $request->user())),
        ));
    }

    /** POST /api/inventory/counts/{count}/recalculate — الكمية بالمخزن من الرصيد الحالي. */
    public function recalculate(Request $request, StockCount $count): JsonResponse
    {
        if ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($count->warehouse_id))) {
            return $denied;
        }

        return $this->attempt(fn () => $this->success('تمت إعادة الاحتساب', $this->present($request, $this->counts->recalculate($count))));
    }

    /** GET /api/inventory/counts/{count}/missing — أصناف لها رصيد في المخزن وليست في الورقة. */
    public function missing(Request $request, StockCount $count): JsonResponse
    {
        if ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($count->warehouse_id))) {
            return $denied;
        }

        return $this->success('تم', $this->counts->missingItems($count));
    }

    /** POST /api/inventory/counts/{count}/lines {stock_item_ids: []} — إضافة أصناف للورقة. */
    public function addLines(Request $request, StockCount $count): JsonResponse
    {
        $data = $this->validateAr($request, [
            'stock_item_ids' => ['required', 'array', 'min:1', 'max:500'],
            'stock_item_ids.*' => ['integer', 'exists:stock_items,id'],
        ]);
        if ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($count->warehouse_id))) {
            return $denied;
        }

        return $this->attempt(fn () => $this->success('أُضيفت الأصناف', $this->present($request, $this->counts->addLines($count, $data['stock_item_ids']))));
    }

    /** POST /api/inventory/counts/{count}/merge {count_ids: []} — دمج مسودات المخزن نفسه في هذه الورقة. */
    public function merge(Request $request, StockCount $count): JsonResponse
    {
        $data = $this->validateAr($request, [
            'count_ids' => ['required', 'array', 'min:1', 'max:20'],
            'count_ids.*' => ['integer', 'distinct', 'exists:stock_counts,id'],
        ]);
        if ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($count->warehouse_id))) {
            return $denied;
        }

        return $this->attempt(fn () => $this->success('تم دمج الجرد', $this->present($request, $this->counts->merge($count, $data['count_ids']))));
    }

    /** POST /api/inventory/counts/{count}/duplicate — تكرار الكشف كمسودة جديدة. */
    public function duplicate(Request $request, StockCount $count): JsonResponse
    {
        if ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($count->warehouse_id))) {
            return $denied;
        }

        return $this->attempt(fn () => $this->success('أُنشئت نسخة من الكشف', $this->present($request, $this->counts->duplicate($count, $request->user())), 201));
    }

    /** @return array<string, mixed> */
    private function present(Request $request, StockCount $count): array
    {
        $count->load([
            'warehouse:id,name,code', 'creator:id,name', 'poster:id,name', 'reverser:id,name',
            'lines' => fn ($q) => $q->join('stock_items', 'stock_items.id', '=', 'stock_count_lines.stock_item_id')
                ->orderBy('stock_items.code')->select('stock_count_lines.*'),
            'lines.stockItem:id,code,name,base_unit_id,safety_stock', 'lines.stockItem.baseUnit:id,name',
            'lines.stockItem.units:id,stock_item_id,unit_id,factor', 'lines.stockItem.units.unit:id,name',
        ]);
        // الرصيد ومتوسط التكلفة الحاليان (سعر الوحدة في المسودة؛ المثبّت يعرض تكلفة التسوية المسجّلة).
        $balances = DB::table('stock_balances')->where('warehouse_id', $count->warehouse_id)
            ->get(['stock_item_id', 'quantity', 'avg_cost'])->keyBy('stock_item_id');
        $showCosts = CostVisibility::canView($request->user());

        $row = $count->toArray();
        $row['day'] = $count->count_date?->toDateString() ?? AccountingDay::fromConfig()->of($count->created_at);
        $row['other_drafts'] = $count->status === 'draft'
            ? StockCount::where('warehouse_id', $count->warehouse_id)->where('status', 'draft')->whereKeyNot($count->id)
                ->withCount(['lines as counted_lines_count' => fn ($q) => $q->whereNotNull('counted_quantity')])
                ->orderBy('id')->get(['id', 'number', 'notes', 'created_at'])->toArray()
            : [];
        $row['lines'] = array_map(function ($l) use ($balances, $showCosts) {
            $b = $balances->get($l['stock_item_id']);
            $l['current_quantity'] = $b ? (string) $b->quantity : '0';
            $l['avg_cost'] = $showCosts ? ($b ? (string) $b->avg_cost : '0') : null;

            return $showCosts ? $l : CostVisibility::strip($l, ['unit_cost', 'variance_value']);
        }, $row['lines']);
        if (! $showCosts) {
            $row = CostVisibility::strip($row, ['variance_value']);
        }
        $row['costs_visible'] = $showCosts;

        return $row;
    }
}
