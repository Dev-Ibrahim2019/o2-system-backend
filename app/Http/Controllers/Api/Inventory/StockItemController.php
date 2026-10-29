<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Models\StockItem;
use App\Services\Inventory\InventoryUsage;
use App\Services\Inventory\StockItemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** الأصناف المخزنية: مواد خام، منتجات وسيطة، منتجات نهائية، أصناف لا تخضع للمخزون. */
class StockItemController extends InventoryController
{
    public function __construct(private StockItemService $items, private InventoryUsage $usage) {}

    /** GET /api/inventory/items?type=&category_id=&search=&is_active=&per_page= */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $items = StockItem::with(['baseUnit:id,name,symbol', 'categories:id,name', 'item:id,name,name_ar,code'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->query('type')))
            ->when($request->filled('category_id'), fn ($q) => $q->whereHas('categories',
                fn ($c) => $c->where('stock_categories.id', $request->integer('category_id'))))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('barcode', $search)
                ->orWhere('supplier_code', $search)))
            ->orderBy('name')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return $this->success('تم', $items);
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
            'tracks_expiry' => ['sometimes', 'boolean'],
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
