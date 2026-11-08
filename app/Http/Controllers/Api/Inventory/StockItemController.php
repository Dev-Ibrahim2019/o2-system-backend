<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Models\StockCategory;
use App\Models\StockItem;
use App\Services\Inventory\InventoryUsage;
use App\Services\Inventory\StockItemService;
use App\Services\Inventory\WarehouseAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** الأصناف المخزنية: مواد خام، منتجات وسيطة، منتجات نهائية، أصناف لا تخضع للمخزون. */
class StockItemController extends InventoryController
{
    public function __construct(private StockItemService $items, private InventoryUsage $usage, private WarehouseAccess $access) {}

    /**
     * GET /api/inventory/items?type=&category_id=&category_tree=&search=&is_active=&with_stock=&per_page=
     *
     * category_tree=1: التصنيف وكل تصنيفاته الفرعية. with_stock=1: الرصيد الإجمالي للصنف في مخازن المستخدم
     * (بالوحدة الأساسية)، وسعر البيع من قائمة الطعام للصنف المرتبط (أقل وأعلى سعر نشط بين الفروع).
     */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $categoryIds = $request->filled('category_id')
            ? ($request->boolean('category_tree') ? $this->withDescendants($request->integer('category_id')) : [$request->integer('category_id')])
            : null;
        $user = $request->user();
        $warehouseIds = $this->access->hasGlobalAccess($user) ? null : $this->access->assignedIds($user);

        $items = StockItem::with(['baseUnit:id,name,symbol', 'categories:id,name,parent_id', 'item:id,name,name_ar,code'])
            ->when($request->boolean('with_stock'), fn ($q) => $q->select('stock_items.*')
                ->selectSub(DB::table('stock_balances')->selectRaw('COALESCE(SUM(quantity), 0)')->whereColumn('stock_item_id', 'stock_items.id')
                    ->when($warehouseIds !== null, fn ($s) => $s->whereIn('warehouse_id', $warehouseIds)), 'total_quantity')
                ->selectSub(DB::table('branch_item')->selectRaw('MIN(price)')->whereColumn('item_id', 'stock_items.item_id')->where('is_active', true), 'sale_price_min')
                ->selectSub(DB::table('branch_item')->selectRaw('MAX(price)')->whereColumn('item_id', 'stock_items.item_id')->where('is_active', true), 'sale_price_max'))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->query('type')))
            ->when($categoryIds !== null, fn ($q) => $q->whereHas('categories',
                fn ($c) => $c->whereIn('stock_categories.id', $categoryIds)))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('barcode', $search)
                ->orWhere('supplier_code', $search)))
            ->orderBy($request->query('sort') === 'code' ? 'code' : 'name')
            ->paginate(min(max($request->integer('per_page', 25), 1), 200));

        return $this->success('تم', $items);
    }

    /** @return array<int> التصنيف وكل ما تحته (الشجرة صغيرة — تُحمَّل كاملة). */
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

    /** GET /api/inventory/items/{item} */
    public function show(StockItem $item): JsonResponse
    {
        $item->load(['baseUnit', 'units.unit', 'categories', 'item', 'purchaseUnit:id,name', 'defaultWarehouse:id,name']);

        return $this->success('تم', [
            ...$item->toArray(),
            'has_movements' => $this->usage->itemHasMovements($item->id),
        ]);
    }

    /** POST /api/inventory/items */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateAr($request, $this->rules($request));

        return $this->attempt(fn () => $this->success('تمت إضافة الصنف', $this->items->create($data, $request->user()), 201));
    }

    /** PUT /api/inventory/items/{item} */
    public function update(Request $request, StockItem $item): JsonResponse
    {
        $data = $this->validateAr($request, $this->rules($request, $item));

        return $this->attempt(fn () => $this->success('تم تحديث الصنف', $this->items->update($item, $data)));
    }

    /** DELETE /api/inventory/items/{item} — ممنوع لصنف له حركات؛ يُوقَف بـ is_active=false. */
    public function destroy(StockItem $item): JsonResponse
    {
        return $this->attempt(function () use ($item) {
            $this->items->delete($item);

            return $this->success('تم حذف الصنف');
        });
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, ?StockItem $item = null): array
    {
        $required = $item ? ['sometimes', 'required'] : ['required'];
        // الاسم اختياري للمنتج النهائي وغير الخاضع للمخزون عند الإنشاء: يُؤخذ من صنف البيع المرتبط.
        $nameRequired = ! $item && ! in_array($request->input('type'), StockItem::SOLD_TYPES, true);

        return [
            'type' => [...$required, Rule::in(StockItem::TYPES)],
            'name' => [$nameRequired ? 'required' : 'sometimes', 'nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'purchase_unit_id' => ['nullable', 'integer', 'exists:stock_units,id'],
            'default_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('stock_items', 'code')->ignore($item?->id)],
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'base_unit_id' => [...$required, 'integer', 'exists:stock_units,id'],
            'is_stored' => ['sometimes', 'boolean'],
            'barcode' => ['nullable', 'string', 'max:64', Rule::unique('stock_items', 'barcode')->ignore($item?->id)],
            'supplier_code' => ['nullable', 'string', 'max:64'],
            'safety_stock' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'units' => ['sometimes', 'array'],
            'units.*.unit_id' => ['required', 'integer', 'exists:stock_units,id'],
            'units.*.factor' => ['required', 'numeric', 'gt:0'],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'exists:stock_categories,id'],
        ];
    }
}
