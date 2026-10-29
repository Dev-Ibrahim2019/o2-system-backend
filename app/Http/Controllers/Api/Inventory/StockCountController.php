<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Models\StockCount;
use App\Models\Warehouse;
use App\Services\Inventory\CostVisibility;
use App\Services\Inventory\StockCountService;
use App\Services\Inventory\WarehouseAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** أوراق الجرد الفعلي. الوصول بحسب المخزن، والإنشاء/الإدخال/الترحيل لكلٍّ صلاحيته. */
class StockCountController extends InventoryController
{
    public function __construct(private StockCountService $counts, private WarehouseAccess $access) {}

    /** GET /api/inventory/counts?warehouse_id=&status=&page= */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $page = StockCount::query()
            ->with(['warehouse:id,name', 'creator:id,name', 'poster:id,name'])
            ->withCount(['lines', 'lines as counted_lines_count' => fn ($q) => $q->whereNotNull('counted_quantity')])
            ->when(! $this->access->hasGlobalAccess($user), fn ($q) => $q->whereIn('warehouse_id', $this->access->assignedIds($user)))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->integer('warehouse_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->where('kind', $request->query('kind') === 'daily' ? 'daily' : 'full')
            ->orderByRaw("status = 'draft' desc")
            ->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        $showCosts = CostVisibility::canView($user);
        $page->getCollection()->transform(fn (StockCount $c) => $showCosts ? $c->toArray() : CostVisibility::strip($c->toArray(), ['variance_value']));

        return $this->success('تم', $page);
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
            'lines.*.dissected' => ['nullable', 'numeric', 'min:0'],
            'lines.*.marinated' => ['nullable', 'numeric', 'min:0'],
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

    /** POST /api/inventory/counts/{count}/post */
    public function post(Request $request, StockCount $count): JsonResponse
    {
        if ($denied = $this->denyUnlessCanAccess($request, Warehouse::findOrFail($count->warehouse_id))) {
            return $denied;
        }

        return $this->attempt(fn () => $this->success(
            'تم ترحيل الجرد وتسوية الفروق',
            $this->present($request, $this->counts->post($count, $request->user())),
        ));
    }

    /** @return array<string, mixed> */
    private function present(Request $request, StockCount $count): array
    {
        $count->load([
            'warehouse:id,name,code', 'creator:id,name', 'poster:id,name',
            'lines' => fn ($q) => $q->join('stock_items', 'stock_items.id', '=', 'stock_count_lines.stock_item_id')
                ->orderBy('stock_items.name')->select('stock_count_lines.*'),
            'lines.stockItem:id,code,name,base_unit_id', 'lines.stockItem.baseUnit:id,name',
            'lines.stockItem.units:id,stock_item_id,unit_id,factor', 'lines.stockItem.units.unit:id,name',
        ]);
        $showCosts = CostVisibility::canView($request->user());
        $row = $count->toArray();
        if (! $showCosts) {
            $row = CostVisibility::strip($row, ['variance_value']);
            $row['lines'] = array_map(fn ($l) => CostVisibility::strip($l, ['unit_cost', 'variance_value']), $row['lines']);
        }
        $row['costs_visible'] = $showCosts;

        return $row;
    }
}
