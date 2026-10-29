<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Models\Branch;
use App\Models\Item;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockUnit;
use App\Services\Inventory\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** وحدات القياس، تصنيفات المواد الخام، وقوائم الاختيار لشاشات الوحدة. */
class CatalogController extends InventoryController
{
    public function __construct(private CatalogService $catalog) {}

    /** GET /api/inventory/lookups */
    public function lookups(): JsonResponse
    {
        return $this->success('تم', [
            'units' => StockUnit::orderBy('name')->get(['id', 'name', 'symbol', 'is_active']),
            'categories' => StockCategory::orderBy('name')->get(['id', 'parent_id', 'name', 'code', 'is_active']),
            'branches' => Branch::orderBy('id')->get(['id', 'name', 'code']),
            'types' => [
                ['value' => StockItem::TYPE_RAW, 'label' => 'مادة خام'],
                ['value' => StockItem::TYPE_INTERMEDIATE, 'label' => 'منتج وسيط'],
                ['value' => StockItem::TYPE_FINISHED, 'label' => 'منتج نهائي'],
                ['value' => StockItem::TYPE_NON_STOCK, 'label' => 'لا يخضع للمخزون'],
            ],
        ]);
    }

    /**
     * GET /api/inventory/sale-items?search=&unlinked=1
     * أصناف قائمة البيع في O2 مع الصنف المخزني المرتبط بكل منها (إن وُجد).
     */
    public function saleItems(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $items = Item::query()
            ->leftJoin('stock_items', 'stock_items.item_id', '=', 'items.id')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('items.name', 'like', "%{$search}%")
                ->orWhere('items.name_ar', 'like', "%{$search}%")
                ->orWhere('items.code', 'like', "%{$search}%")))
            ->when($request->boolean('unlinked'), fn ($q) => $q->whereNull('stock_items.id'))
            ->orderBy('items.name_ar')
            ->limit(50)
            ->get(['items.id', 'items.name', 'items.name_ar', 'items.code', 'items.is_active', 'stock_items.id as stock_item_id']);

        return $this->success('تم', $items);
    }

    /** GET /api/inventory/units */
    public function units(): JsonResponse
    {
        return $this->success('تم', StockUnit::orderBy('name')->get());
    }

    /** POST /api/inventory/units */
    public function storeUnit(Request $request): JsonResponse
    {
        $data = $this->validateAr($request, [
            'name' => ['required', 'string', 'max:100', 'unique:stock_units,name'],
            'symbol' => ['nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return $this->success('تمت إضافة الوحدة', StockUnit::create($data), 201);
    }

    /** PUT /api/inventory/units/{unit} */
    public function updateUnit(Request $request, StockUnit $unit): JsonResponse
    {
        $data = $this->validateAr($request, [
            'name' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('stock_units', 'name')->ignore($unit->id)],
            'symbol' => ['nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $unit->update($data);

        return $this->success('تم تحديث الوحدة', $unit->fresh());
    }

    /** DELETE /api/inventory/units/{unit} */
    public function destroyUnit(StockUnit $unit): JsonResponse
    {
        return $this->attempt(function () use ($unit) {
            $this->catalog->deleteUnit($unit);

            return $this->success('تم حذف الوحدة');
        });
    }

    /** GET /api/inventory/categories — قائمة مسطّحة؛ الواجهة تبني الشجرة من parent_id. */
    public function categories(): JsonResponse
    {
        return $this->success('تم', StockCategory::withCount('items')->orderBy('name')->get());
    }

    /** POST /api/inventory/categories */
    public function storeCategory(Request $request): JsonResponse
    {
        $data = $this->validateAr($request, $this->categoryRules());

        return $this->attempt(fn () => $this->success('تمت إضافة التصنيف', $this->catalog->saveCategory($data), 201));
    }

    /** PUT /api/inventory/categories/{category} */
    public function updateCategory(Request $request, StockCategory $category): JsonResponse
    {
        $data = $this->validateAr($request, $this->categoryRules($category));

        return $this->attempt(fn () => $this->success('تم تحديث التصنيف', $this->catalog->saveCategory($data, $category)));
    }

    /** DELETE /api/inventory/categories/{category} */
    public function destroyCategory(StockCategory $category): JsonResponse
    {
        return $this->attempt(function () use ($category) {
            $this->catalog->deleteCategory($category);

            return $this->success('تم حذف التصنيف');
        });
    }

    /** @return array<string, mixed> */
    private function categoryRules(?StockCategory $category = null): array
    {
        $required = $category ? ['sometimes', 'required'] : ['required'];

        return [
            'name' => [...$required, 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:30', Rule::unique('stock_categories', 'code')->ignore($category?->id)],
            'parent_id' => ['nullable', 'integer', 'exists:stock_categories,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
