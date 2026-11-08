<?php

namespace App\Services\Inventory;

use App\Models\Item;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * إنشاء وتعديل وحذف الأصناف المخزنية مع قواعد العمل:
 *
 *  - المنتج النهائي والصنف الذي لا يخضع للمخزون يجب ربطهما بصنف O2 مباع
 *    (items) — هذا الربط هو ما يصل البيع بالمخزون في المرحلة 5.
 *  - المادة الخام والمنتج الوسيط لا يُربطان بصنف مباع.
 *  - المادة الخام تُخزَّن دائماً، والصنف الذي لا يخضع للمخزون لا يُخزَّن أبداً.
 *    علم "يُخزَّن" قرار فعلي للمنتج الوسيط والنهائي فقط (القرار 1).
 *  - لا يُحذف صنف له حركات ولا تُغيَّر وحدته الأساسية أو نوعه؛ يُوقَف فقط.
 */
class StockItemService
{
    private const CODE_PREFIX = [
        StockItem::TYPE_RAW => 'RM',
        StockItem::TYPE_INTERMEDIATE => 'IM',
        StockItem::TYPE_FINISHED => 'FP',
        StockItem::TYPE_NON_STOCK => 'NS',
    ];

    public function __construct(private InventoryUsage $usage) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $by): StockItem
    {
        $data = $this->normalize($data, null);

        return DB::transaction(function () use ($data, $by) {
            $item = StockItem::create([
                ...Arr::except($data, ['units', 'category_ids']),
                'code' => $data['code'] ?? $this->nextCode($data['type']),
                'created_by' => $by->id,
            ]);

            $this->syncUnits($item, $data['units'] ?? []);
            $this->syncCategories($item, $data['category_ids'] ?? []);

            return $item->load(['baseUnit', 'units.unit', 'categories', 'item']);
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(StockItem $item, array $data): StockItem
    {
        $data = $this->normalize($data, $item);

        if (array_key_exists('base_unit_id', $data)
            && (int) $data['base_unit_id'] !== (int) $item->base_unit_id
            && $this->usage->itemHasMovements($item->id)) {
            throw new InventoryRuleException('لا يمكن تغيير الوحدة الأساسية لصنف له حركات مخزنية.');
        }

        return DB::transaction(function () use ($item, $data) {
            $item->update(Arr::except($data, ['units', 'category_ids', 'code']));

            if (array_key_exists('units', $data)) {
                $this->syncUnits($item, $data['units']);
            }
            if (array_key_exists('category_ids', $data)) {
                $this->syncCategories($item, $data['category_ids']);
            }

            return $item->fresh(['baseUnit', 'units.unit', 'categories', 'item']);
        });
    }

    public function delete(StockItem $item): void
    {
        if ($this->usage->itemHasMovements($item->id)) {
            throw new InventoryRuleException('لا يمكن حذف صنف له حركات مخزنية. يمكنك إيقافه بدلاً من ذلك.');
        }

        DB::transaction(function () use ($item) {
            InventoryAudit::relation($item, 'category_ids', $item->categories()->pluck('stock_categories.id')->all(), []);
            $item->delete();
        });
    }

    /**
     * يطبّق قواعد النوع على البيانات المُدخلة (في الإنشاء $current = null).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data, ?StockItem $current): array
    {
        $type = $data['type'] ?? $current?->type;

        if ($current && isset($data['type']) && $data['type'] !== $current->type
            && $this->usage->itemHasMovements($current->id)) {
            throw new InventoryRuleException('لا يمكن تغيير نوع صنف له حركات مخزنية.');
        }

        $itemId = array_key_exists('item_id', $data) ? $data['item_id'] : $current?->item_id;

        if (in_array($type, StockItem::SOLD_TYPES, true)) {
            if (! $itemId) {
                throw new InventoryRuleException('يجب ربط المنتج النهائي أو الصنف غير الخاضع للمخزون بصنف من قائمة البيع.',
                    ['item_id' => ['اختر صنف البيع المرتبط.']]);
            }
            $linkedElsewhere = StockItem::where('item_id', $itemId)
                ->when($current, fn ($q) => $q->where('id', '!=', $current->id))
                ->exists();
            if ($linkedElsewhere) {
                throw new InventoryRuleException('صنف البيع هذا مربوط بصنف مخزني آخر.',
                    ['item_id' => ['صنف البيع مربوط مسبقاً.']]);
            }
            if (! $current && empty($data['name'])) {
                $sold = Item::find($itemId);
                $data['name'] = $sold?->name_ar ?: $sold?->name;
            }
        } elseif ($itemId) {
            throw new InventoryRuleException('المادة الخام والمنتج الوسيط لا يُربطان بصنف من قائمة البيع.',
                ['item_id' => ['اترك هذا الحقل فارغاً لهذا النوع.']]);
        }

        if ($type === StockItem::TYPE_RAW) {
            $data['is_stored'] = true;
        } elseif ($type === StockItem::TYPE_NON_STOCK) {
            $data['is_stored'] = false;
        }

        $baseUnitId = (int) ($data['base_unit_id'] ?? $current?->base_unit_id);
        $unitIds = array_map(fn ($u) => (int) $u['unit_id'], $data['units'] ?? []);
        if (in_array($baseUnitId, $unitIds, true)) {
            throw new InventoryRuleException('الوحدة الأساسية لا تُضاف ضمن الوحدات البديلة (معاملها 1 دائماً).',
                ['units' => ['احذف الوحدة الأساسية من الوحدات البديلة.']]);
        }
        if (count($unitIds) !== count(array_unique($unitIds))) {
            throw new InventoryRuleException('الوحدة نفسها مكرّرة في الوحدات البديلة.', ['units' => ['وحدة مكرّرة.']]);
        }

        // وحدة الشراء: الأساسية أو إحدى البديلة (المرسلة الآن، أو الحالية إن لم تُرسل).
        $purchaseUnitId = array_key_exists('purchase_unit_id', $data) ? $data['purchase_unit_id'] : $current?->purchase_unit_id;
        if ($purchaseUnitId) {
            $known = array_key_exists('units', $data) || ! $current
                ? $unitIds
                : $current->units()->pluck('unit_id')->map(fn ($id) => (int) $id)->all();
            if ((int) $purchaseUnitId !== $baseUnitId && ! in_array((int) $purchaseUnitId, $known, true)) {
                throw new InventoryRuleException('وحدة الشراء يجب أن تكون الوحدة الأساسية أو إحدى الوحدات البديلة.',
                    ['purchase_unit_id' => ['أضف الوحدة ضمن الوحدات البديلة أولاً.']]);
            }
        }

        return $data;
    }

    /** @param  array<int, array{unit_id:int, factor:numeric}>  $units */
    private function syncUnits(StockItem $item, array $units): void
    {
        $existing = $item->units()->get()->keyBy('unit_id');
        $incoming = collect($units)->keyBy(fn ($u) => (int) $u['unit_id']);

        // تعديل معامل وحدة لصنف له حركات لا يغيّر الكميات المخزنة (كلها بالوحدة
        // الأساسية)، فهو مسموح — يؤثر على الإدخالات القادمة فقط.
        foreach ($existing as $unitId => $row) {
            if (! $incoming->has($unitId)) {
                $row->delete();
            }
        }
        foreach ($incoming as $unitId => $unit) {
            $factor = UnitConverter::round((string) $unit['factor']);
            $row = $existing->get($unitId);
            if ($row) {
                if (bccomp((string) $row->factor, $factor, UnitConverter::SCALE) !== 0) {
                    $row->update(['factor' => $factor]);
                }
            } else {
                $item->units()->create(['unit_id' => $unitId, 'factor' => $factor]);
            }
        }
    }

    /** @param  array<int>  $categoryIds */
    private function syncCategories(StockItem $item, array $categoryIds): void
    {
        $old = $item->categories()->pluck('stock_categories.id')->map(fn ($id) => (int) $id)->all();
        $new = array_values(array_unique(array_map('intval', $categoryIds)));
        $item->categories()->sync($new);
        InventoryAudit::relation($item, 'category_ids', $old, $new);
    }

    private function nextCode(string $type): string
    {
        $prefix = self::CODE_PREFIX[$type].'-';
        $last = StockItem::where('code', 'like', $prefix.'%')
            ->orderByRaw('CAST(SUBSTRING(code, ?) AS UNSIGNED) DESC', [strlen($prefix) + 1])
            ->value('code');
        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
