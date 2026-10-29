<?php

namespace App\Services\Inventory;

use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockItemUnit;
use App\Models\StockUnit;

/** قواعد التصنيفات ووحدات القياس. */
class CatalogService
{
    /** @param  array<string, mixed>  $data */
    public function saveCategory(array $data, ?StockCategory $category = null): StockCategory
    {
        $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $category?->parent_id;

        if ($category && $parentId !== null) {
            $parents = StockCategory::pluck('parent_id', 'id');
            $cycle = CategoryTree::wouldCreateCycle(
                $category->id,
                (int) $parentId,
                fn (int $id) => isset($parents[$id]) ? (int) $parents[$id] : null,
            );
            if ($cycle) {
                throw new InventoryRuleException('لا يمكن جعل التصنيف تابعاً لنفسه أو لأحد فروعه.',
                    ['parent_id' => ['اختيار الأب يُنشئ حلقة في الشجرة.']]);
            }
        }

        if ($category) {
            $category->update($data);

            return $category->fresh();
        }

        return StockCategory::create($data);
    }

    public function deleteCategory(StockCategory $category): void
    {
        if ($category->children()->exists()) {
            throw new InventoryRuleException('لا يمكن حذف تصنيف له تصنيفات فرعية. انقلها أو احذفها أولاً.');
        }
        if ($category->items()->exists()) {
            throw new InventoryRuleException('لا يمكن حذف تصنيف مرتبط بأصناف. أزل الأصناف منه أو أوقفه.');
        }

        $category->delete();
    }

    public function deleteUnit(StockUnit $unit): void
    {
        $used = StockItem::where('base_unit_id', $unit->id)->exists()
            || StockItemUnit::where('unit_id', $unit->id)->exists();

        if ($used) {
            throw new InventoryRuleException('لا يمكن حذف وحدة مستخدمة في أصناف. يمكنك إيقافها.');
        }

        $unit->delete();
    }
}
