<?php

namespace App\Services\Inventory;

use App\Models\Recipe;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * الوصفات: حفظ النسخ، واختيار النسخة السارية في يوم معيّن، وتفكيكها إلى مواد خام، وتكلفتها.
 *
 * النسخة لا تُعدَّل بعد أن يبدأ سريانها في يوم سابق — التعديل نسخة جديدة من اليوم (أو لاحقاً)،
 * حتى يبقى الاستهلاك النظري للفترات الماضية محسوباً بالوصفة التي كانت سارية فعلاً.
 */
class RecipeService
{
    /** أقصى عمق لوصفة فرعية داخل وصفة (يحمي من الحلقات). */
    public const MAX_DEPTH = 5;

    private const WORK = UnitConverter::SCALE + 6;

    public function __construct(private ?AccountingDay $accountingDay = null)
    {
        $this->accountingDay ??= AccountingDay::fromConfig();
    }

    /**
     * حفظ نسخة لمالك: منتج منيو ($itemId) أو منتج وسيط ($stockItemId).
     * نسخة بنفس تاريخ السريان (اليوم أو لاحقاً) تُستبدل؛ وإلا تُنشأ جديدة.
     *
     * @param  array{effective_from?: ?string, yield_quantity?: mixed, notes?: ?string, lines: array<int, array{stock_item_id:int, unit_id?:?int, quantity:mixed}>}  $data
     */
    public function save(?int $itemId, ?int $stockItemId, array $data, User $by): Recipe
    {
        $today = $this->accountingDay->of(now());
        $from = ($data['effective_from'] ?? null) ?: $today;
        if ($from < $today) {
            throw new InventoryRuleException('لا يمكن إنشاء نسخة وصفة بتاريخ سابق — حتى لا تتغير حسابات الأيام الماضية.',
                ['effective_from' => ['اختر اليوم أو تاريخاً لاحقاً.']]);
        }

        if ($stockItemId) {
            $owner = StockItem::findOrFail($stockItemId);
            // منتج وسيط (ثومية) أو منتج نهائي مخزّن يُصنَّع (كيك، حلويات، بوظة — الوصفة لكل 1 كغ منه).
            if (! in_array($owner->type, [StockItem::TYPE_INTERMEDIATE, StockItem::TYPE_FINISHED], true) || ! $owner->is_stored) {
                throw new InventoryRuleException('وصفة الصنف المخزني تكون لمنتج وسيط أو منتج نهائي مخزّن فقط.');
            }
        }

        $yield = (string) ($data['yield_quantity'] ?? '1');
        if (bccomp($yield, '0', UnitConverter::SCALE) <= 0) {
            throw new InventoryRuleException('كمية الناتج يجب أن تكون أكبر من صفر.', ['yield_quantity' => ['أدخل كمية موجبة.']]);
        }

        $lines = $this->resolveLines($data['lines'], $stockItemId);

        return DB::transaction(function () use ($itemId, $stockItemId, $from, $yield, $data, $lines, $by) {
            $recipe = Recipe::query()
                ->where($itemId ? 'item_id' : 'stock_item_id', $itemId ?: $stockItemId)
                ->where('effective_from', $from)
                ->lockForUpdate()
                ->first();

            if ($recipe) {
                $recipe->update(['yield_quantity' => $yield, 'notes' => $data['notes'] ?? null]);
                $recipe->lines()->delete();
            } else {
                $recipe = Recipe::create([
                    'item_id' => $itemId,
                    'stock_item_id' => $stockItemId,
                    'effective_from' => $from,
                    'yield_quantity' => $yield,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $by->id,
                ]);
            }
            $recipe->lines()->createMany($lines);

            // التفكيك يرفض الحلقات (ثومية ← صوص ← ثومية) والعمق الزائد؛ الرفض يلغي الحفظ كله.
            $this->explode($recipe->fresh('lines'), $from);

            return $recipe->fresh(['lines.stockItem:id,code,name,type,base_unit_id', 'lines.stockItem.baseUnit:id,name', 'lines.unit:id,name']);
        });
    }

    /** حذف نسخة لم يبدأ سريانها قبل اليوم. */
    public function deleteVersion(Recipe $recipe): void
    {
        if ($recipe->effective_from->toDateString() < $this->accountingDay->of(now())) {
            throw new InventoryRuleException('لا تُحذف نسخة بدأ سريانها في يوم سابق؛ أنشئ نسخة جديدة بدلاً منها.');
        }
        $recipe->delete();
    }

    /** النسخة السارية في يوم محاسبي (آخر نسخة تاريخ سريانها ≤ اليوم). */
    public function versionAt(?int $itemId, ?int $stockItemId, string $day): ?Recipe
    {
        return Recipe::with('lines')
            ->where($itemId ? 'item_id' : 'stock_item_id', $itemId ?: $stockItemId)
            ->where('effective_from', '<=', $day)
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * تفكيك نسخة وصفة إلى مواد بالوحدة الأساسية لكل وحدة واحدة من المالك
     * (حصة واحدة من المنتج، أو وحدة أساسية واحدة من المنتج الوسيط).
     *
     * كمية المكوّن لكل وحدة = كمية المكوّن في الوصفة ÷ كمية الناتج.
     * المكوّن الذي هو منتج وسيط له وصفة سارية يُفكَّك بدوره:
     *   كمية المادة الخام = كمية الوسيط لكل وحدة × كمية المادة لكل وحدة من الوسيط.
     * مثال: ساندويش فيه 30 غ ثومية، ووصفة الثومية 1000 غ ناتج من 200 غ ثوم
     *   → ثوم لكل ساندويش = 30 × (200 ÷ 1000) = 6 غ.
     *
     * @param  array<int, true>  $path  المنتجات الوسيطة في المسار الحالي (لكشف الحلقات)
     * @param  (callable(int $stockItemId, string $day): ?Recipe)|null  $subVersion  مصدر نسخ الوصفات الفرعية
     *         (التحليلات تمرّر نسخاً محمّلة في الذاكرة بدل استعلام لكل مكوّن)
     * @return array<int, string> stock_item_id => الكمية الأساسية لكل وحدة
     */
    public function explode(Recipe $recipe, string $day, int $depth = 0, array $path = [], ?callable $subVersion = null): array
    {
        $subVersion ??= fn (int $id, string $d) => $this->versionAt(null, $id, $d);
        if ($depth > self::MAX_DEPTH) {
            throw new InventoryRuleException('الوصفات الفرعية متداخلة بعمق أكبر من المسموح ('.self::MAX_DEPTH.').');
        }
        if ($recipe->stock_item_id) {
            $path[$recipe->stock_item_id] = true;
        }

        $result = [];
        foreach ($recipe->lines as $line) {
            if (isset($path[$line->stock_item_id])) {
                throw new InventoryRuleException('الوصفة تحتوي حلقة: المنتج الوسيط يدخل في وصفته نفسها.');
            }
            $perUnit = bcdiv((string) $line->base_quantity, (string) $recipe->yield_quantity, self::WORK);
            $sub = $subVersion((int) $line->stock_item_id, $day);
            if ($sub) {
                foreach ($this->explode($sub, $day, $depth + 1, $path, $subVersion) as $rawId => $qty) {
                    $result[$rawId] = bcadd($result[$rawId] ?? '0', bcmul($perUnit, $qty, self::WORK), self::WORK);
                }
            } else {
                $result[$line->stock_item_id] = bcadd($result[$line->stock_item_id] ?? '0', $perUnit, self::WORK);
            }
        }

        return $result;
    }

    /**
     * تكلفة الوحدة الحالية لكل مادة: متوسط مرجّح عبر المخازن (قيمة الأرصدة الموجبة ÷ كمياتها)،
     * وإن لم يوجد رصيد فآخر تكلفة شراء، وإلا صفر.
     *
     * @param  array<int>  $stockItemIds
     * @return array<int, string>
     */
    public function currentUnitCosts(array $stockItemIds): array
    {
        if ($stockItemIds === []) {
            return [];
        }
        $avg = DB::table('stock_balances')
            ->whereIn('stock_item_id', $stockItemIds)
            ->where('quantity', '>', 0)
            ->groupBy('stock_item_id')
            ->selectRaw('stock_item_id, SUM(quantity * avg_cost) / SUM(quantity) as cost')
            ->pluck('cost', 'stock_item_id');
        $last = StockItem::whereIn('id', $stockItemIds)->pluck('last_cost', 'id');

        $costs = [];
        foreach ($stockItemIds as $id) {
            $costs[$id] = UnitConverter::round((string) ($avg[$id] ?? $last[$id] ?? '0'));
        }

        return $costs;
    }

    /**
     * تكلفة وحدة واحدة من المالك بالتكاليف الحالية = Σ (كمية المادة لكل وحدة × تكلفة وحدتها).
     *
     * @return array{cost: string, ingredients: array<int, array{stock_item_id:int, quantity:string, unit_cost:string, cost:string}>}
     */
    public function unitCost(Recipe $recipe, string $day): array
    {
        $exploded = $this->explode($recipe, $day);
        $costs = $this->currentUnitCosts(array_keys($exploded));
        $weight = array_reduce($exploded, fn ($c, $q) => bcadd($c, $q, self::WORK), '0');

        $total = '0';
        $ingredients = [];
        foreach ($exploded as $id => $qty) {
            $cost = bcmul($qty, $costs[$id], self::WORK);
            $total = bcadd($total, $cost, self::WORK);
            $ingredients[] = [
                'stock_item_id' => $id,
                'quantity' => UnitConverter::round($qty),
                'unit_cost' => $costs[$id],
                'cost' => UnitConverter::round($cost),
                // نسبة المكوّن من وزن المواد = كميته ÷ مجموع كميات المكوّنات × 100
                'weight_percent' => bccomp($weight, '0', 6) > 0 ? bcdiv(bcmul($qty, '100', self::WORK), $weight, 2) : null,
            ];
        }

        return ['cost' => UnitConverter::round($total), 'ingredients' => $ingredients];
    }

    /**
     * @param  array<int, array{stock_item_id:int, unit_id?:?int, quantity:mixed}>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function resolveLines(array $lines, ?int $ownerStockItemId): array
    {
        if ($lines === []) {
            throw new InventoryRuleException('أضف مكوّناً واحداً على الأقل للوصفة.');
        }
        $ids = array_map(fn ($l) => (int) $l['stock_item_id'], $lines);
        if (count($ids) !== count(array_unique($ids))) {
            throw new InventoryRuleException('المكوّن نفسه مكرّر في الوصفة.');
        }
        if ($ownerStockItemId && in_array($ownerStockItemId, $ids, true)) {
            throw new InventoryRuleException('المنتج الوسيط لا يدخل في وصفته نفسها.');
        }

        $items = StockItem::with('units')->whereIn('id', $ids)->get()->keyBy('id');
        $resolved = [];
        foreach (array_values($lines) as $i => $l) {
            $item = $items->get((int) $l['stock_item_id']);
            $n = $i + 1;
            if (! $item || ! $item->is_stored) {
                throw new InventoryRuleException("المكوّن {$n}: الصنف لا يخضع للتخزين.");
            }
            if (! in_array($item->type, [StockItem::TYPE_RAW, StockItem::TYPE_INTERMEDIATE], true)) {
                throw new InventoryRuleException("المكوّن {$n}: \"{$item->name}\" ليس مادة خام ولا منتجاً وسيطاً.");
            }
            $unitId = (int) ($l['unit_id'] ?? 0) ?: (int) $item->base_unit_id;
            if ($unitId === (int) $item->base_unit_id) {
                $factor = '1';
            } else {
                $alt = $item->units->firstWhere('unit_id', $unitId);
                if (! $alt) {
                    throw new InventoryRuleException("المكوّن {$n}: الوحدة غير معرّفة للصنف \"{$item->name}\".");
                }
                $factor = (string) $alt->factor;
            }
            // الكمية الأساسية = الكمية المُدخلة × معامل الوحدة (0.12 كغ × 1000 = 120 غ)
            $base = UnitConverter::toBase($l['quantity'], $factor);
            if (bccomp($base, '0', UnitConverter::SCALE) <= 0) {
                throw new InventoryRuleException("المكوّن {$n}: الكمية يجب أن تكون أكبر من صفر.");
            }
            $resolved[] = [
                'stock_item_id' => $item->id,
                'unit_id' => $unitId,
                'quantity' => UnitConverter::toBase($l['quantity'], '1'),
                'base_quantity' => $base,
            ];
        }

        return $resolved;
    }
}
