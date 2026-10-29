<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Models\Item;
use App\Models\Recipe;
use App\Models\StockItem;
use App\Services\Inventory\AccountingDay;
use App\Services\Inventory\CostVisibility;
use App\Services\Inventory\RecipeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * الوصفات لمنتجات المنيو (product = items) وللمنتجات الوسيطة (intermediate = stock_items).
 * التكلفة بالتكاليف الحالية (متوسط مرجّح)، وتُخفى عمّن لا يملك inventory.costs.view.
 */
class RecipeController extends InventoryController
{
    public function __construct(private RecipeService $recipes) {}

    /** GET /api/inventory/recipes?type=product|intermediate&search=&missing=1&page= */
    public function index(Request $request): JsonResponse
    {
        $type = $request->query('type', 'product') === 'intermediate' ? 'intermediate' : 'product';
        $search = trim((string) $request->query('search', ''));
        $today = AccountingDay::fromConfig()->of(now());
        $table = $type === 'product' ? 'items' : 'stock_items';
        $ownerColumn = $type === 'product' ? 'item_id' : 'stock_item_id';
        $hasRecipe = fn ($q) => $q->select(DB::raw(1))->from('recipes')
            ->whereColumn("recipes.{$ownerColumn}", "{$table}.id")
            ->where('recipes.effective_from', '<=', $today);

        $query = $type === 'product'
            ? Item::query()->where('is_active', true)->select(['id', 'name', 'name_ar', 'code'])
                ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                    ->orWhere('name_ar', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
                ->orderBy('name_ar')
            : StockItem::query()->whereIn('type', [StockItem::TYPE_INTERMEDIATE, StockItem::TYPE_FINISHED])->where('is_stored', true)->with('baseUnit:id,name')
                ->select(['id', 'name', 'code', 'base_unit_id'])
                ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")))
                ->orderBy('name');

        $page = $query
            ->when($request->boolean('missing'), fn ($q) => $q->whereNotExists($hasRecipe))
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        $showCosts = CostVisibility::canView($request->user());
        $prices = $type === 'product' ? $this->prices($page->getCollection()->pluck('id')->all()) : [];

        $page->getCollection()->transform(function ($owner) use ($type, $today, $showCosts, $prices) {
            $recipe = $this->recipes->versionAt($type === 'product' ? $owner->id : null, $type === 'intermediate' ? $owner->id : null, $today);
            $cost = $recipe ? $this->recipes->unitCost($recipe, $today)['cost'] : null;
            $price = $prices[$owner->id] ?? null;

            return [
                'id' => $owner->id,
                'name' => $type === 'product' ? ($owner->name_ar ?: $owner->name) : $owner->name,
                'code' => $owner->code,
                'base_unit' => $type === 'intermediate' ? $owner->baseUnit?->name : null,
                'has_recipe' => $recipe !== null,
                'effective_from' => $recipe?->effective_from?->toDateString(),
                'lines_count' => $recipe?->lines->count() ?? 0,
                'price' => $price,
                'unit_cost' => $showCosts ? $cost : null,
                'food_cost_percent' => $showCosts ? $this->foodCostPercent($cost, $price) : null,
            ];
        });

        return $this->success('تم', $page);
    }

    /** GET /api/inventory/recipes/{type}/{id} — كل النسخ والنسخة السارية وتكلفتها مفصّلة. */
    public function show(Request $request, string $type, int $id): JsonResponse
    {
        [$itemId, $stockItemId, $owner] = $this->owner($type, $id);
        $today = AccountingDay::fromConfig()->of(now());
        $showCosts = CostVisibility::canView($request->user());

        $versions = Recipe::with(['lines.stockItem:id,code,name,type,base_unit_id', 'lines.stockItem.baseUnit:id,name', 'lines.unit:id,name', 'creator:id,name'])
            ->where($itemId ? 'item_id' : 'stock_item_id', $id)
            ->orderByDesc('effective_from')
            ->get();
        $current = $this->recipes->versionAt($itemId, $stockItemId, $today);

        $costing = null;
        if ($current) {
            $costing = $this->recipes->unitCost($current, $today);
            $names = StockItem::with('baseUnit:id,name')->whereIn('id', array_column($costing['ingredients'], 'stock_item_id'))
                ->get(['id', 'name', 'code', 'base_unit_id'])->keyBy('id');
            $costing['ingredients'] = array_map(fn ($i) => [
                ...$i,
                'name' => $names[$i['stock_item_id']]->name ?? '',
                'base_unit' => $names[$i['stock_item_id']]->baseUnit?->name,
            ], $costing['ingredients']);
        }
        $price = $itemId ? ($this->prices([$itemId])[$itemId] ?? null) : null;
        $foodCost = $showCosts && $costing ? $this->foodCostPercent($costing['cost'], $price) : null;
        if ($costing && ! $showCosts) {
            $costing['cost'] = null;
            $costing['ingredients'] = array_map(fn ($i) => CostVisibility::strip($i, ['unit_cost', 'cost']), $costing['ingredients']);
        }

        return $this->success('تم', [
            'type' => $type,
            'owner' => $owner,
            'today' => $today,
            'current_id' => $current?->id,
            'price' => $price,
            'costing' => $costing,
            'food_cost_percent' => $foodCost,
            'costs_visible' => $showCosts,
            'versions' => $versions->map(fn (Recipe $r) => [
                ...$r->toArray(),
                'is_current' => $r->id === $current?->id,
                'editable' => $r->effective_from->toDateString() >= $today,
            ]),
        ]);
    }

    /** POST /api/inventory/recipes/{type}/{id} {effective_from?, yield_quantity?, notes?, lines:[{stock_item_id, unit_id?, quantity}]} */
    public function store(Request $request, string $type, int $id): JsonResponse
    {
        [$itemId, $stockItemId] = $this->owner($type, $id);
        $data = $this->validateAr($request, [
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'yield_quantity' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.stock_item_id' => ['required', 'integer', 'distinct', 'exists:stock_items,id'],
            'lines.*.unit_id' => ['nullable', 'integer', 'exists:stock_units,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        return $this->attempt(fn () => $this->success(
            'تم حفظ الوصفة',
            $this->recipes->save($itemId, $stockItemId, $data, $request->user()),
            201,
        ));
    }

    /** DELETE /api/inventory/recipes/versions/{recipe} — لنسخة لم يبدأ سريانها قبل اليوم. */
    public function destroy(Recipe $recipe): JsonResponse
    {
        return $this->attempt(function () use ($recipe) {
            $this->recipes->deleteVersion($recipe);

            return $this->success('تم حذف النسخة');
        });
    }

    /** @return array{0: ?int, 1: ?int, 2: array<string, mixed>} */
    private function owner(string $type, int $id): array
    {
        abort_unless(in_array($type, ['product', 'intermediate'], true), 404);
        if ($type === 'product') {
            $item = Item::findOrFail($id, ['id', 'name', 'name_ar', 'code']);

            return [$item->id, null, ['id' => $item->id, 'name' => $item->name_ar ?: $item->name, 'code' => $item->code, 'base_unit' => null]];
        }
        $stock = StockItem::with('baseUnit:id,name')->whereIn('type', [StockItem::TYPE_INTERMEDIATE, StockItem::TYPE_FINISHED])
            ->where('is_stored', true)->findOrFail($id, ['id', 'name', 'code', 'base_unit_id']);

        return [null, $stock->id, ['id' => $stock->id, 'name' => $stock->name, 'code' => $stock->code, 'base_unit' => $stock->baseUnit?->name]];
    }

    /**
     * سعر البيع = متوسط أسعار الصنف في الفروع التي يُباع فيها (branch_item النشطة).
     *
     * @param  array<int>  $itemIds
     * @return array<int, string>
     */
    private function prices(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return DB::table('branch_item')
            ->whereIn('item_id', $itemIds)
            ->where('is_active', true)
            ->whereNotNull('price')
            ->groupBy('item_id')
            ->selectRaw('item_id, ROUND(AVG(price), 3) as price')
            ->pluck('price', 'item_id')
            ->map(fn ($p) => (string) $p)
            ->all();
    }

    /** نسبة تكلفة الطعام = تكلفة الوصفة ÷ سعر البيع × 100 */
    private function foodCostPercent(?string $cost, ?string $price): ?string
    {
        if ($cost === null || $price === null || bccomp($price, '0', 3) <= 0) {
            return null;
        }

        return bcdiv(bcmul($cost, '100', 8), $price, 2);
    }
}
