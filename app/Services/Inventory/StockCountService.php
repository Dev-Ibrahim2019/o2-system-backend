<?php

namespace App\Services\Inventory;

use App\Models\StockCount;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * الجرد: ورقة لمخزن ← إدخال المعدود ← ترحيل يسجّل حركة "تسوية جرد" للفرق عبر StockLedger.
 * الفرق مقابل الرصيد المسجّل لحظة الترحيل، والصنف غير المعدود لا يُسوّى. المرحّل لا يُعدَّل؛ يُصحَّح بجرد جديد.
 */
class StockCountService
{
    public function __construct(
        private StockLedger $ledger,
        private ?AccountingDay $accountingDay = null,
    ) {
        $this->accountingDay ??= AccountingDay::fromConfig();
    }

    /** ورقة جرد بكل الأصناف المخزنية النشطة (أو تصنيف واحد)، مع الرصيد المسجّل حالياً. */
    public function create(Warehouse $warehouse, ?int $categoryId, ?string $notes, User $by): StockCount
    {
        $this->assertActive($warehouse);
        $open = StockCount::where('warehouse_id', $warehouse->id)->where('kind', 'full')->where('status', 'draft')->value('number');
        if ($open) {
            throw new InventoryRuleException("يوجد جرد مفتوح لهذا المخزن ({$open}). رحّله أو احذفه أولاً.");
        }

        $items = StockItem::query()
            ->where('is_stored', true)
            ->where('is_active', true)
            ->when($categoryId, fn ($q) => $q->whereHas('categories', fn ($c) => $c->where('stock_categories.id', $categoryId)))
            ->orderBy('name')
            ->pluck('id');
        if ($items->isEmpty()) {
            throw new InventoryRuleException('لا توجد أصناف مخزنية لجردها.');
        }
        $balances = DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->pluck('quantity', 'stock_item_id');

        return DB::transaction(function () use ($warehouse, $items, $balances, $notes, $by) {
            $count = StockCount::create(['warehouse_id' => $warehouse->id, 'kind' => 'full', 'notes' => $notes, 'created_by' => $by->id]);
            $count->forceFill(['number' => 'CNT-'.str_pad((string) $count->id, 6, '0', STR_PAD_LEFT)])->save();
            $count->lines()->createMany($items->map(fn ($id) => ['stock_item_id' => $id, 'expected_quantity' => (string) ($balances[$id] ?? '0')])->all());

            return $count;
        });
    }

    /**
     * حفظ المعدود. entered_quantity فارغ = لم يُعدّ.
     *
     * @param  array<int, array{stock_item_id:int, unit_id?:?int, entered_quantity:mixed}>  $lines
     */
    public function saveCounts(StockCount $count, array $lines, ?string $notes): StockCount
    {
        return DB::transaction(function () use ($count, $lines, $notes) {
            $count = $this->lockDraft($count);
            $existing = $count->lines()->with('stockItem.units')->get()->keyBy('stock_item_id');

            foreach ($lines as $l) {
                $line = $existing->get((int) $l['stock_item_id']);
                if (! $line) {
                    throw new InventoryRuleException('صنف غير موجود في ورقة الجرد.');
                }
                $entered = $l['entered_quantity'] ?? null;
                if ($entered === null || $entered === '') {
                    $line->forceFill(['unit_id' => null, 'entered_quantity' => null, 'counted_quantity' => null])->save();

                    continue;
                }
                $item = $line->stockItem;
                $unitId = (int) ($l['unit_id'] ?? 0) ?: (int) $item->base_unit_id;
                // المعدود بالوحدة الأساسية = المُدخل × معامل الوحدة (3 كراتين × 10000 = 30000 غ)
                $counted = UnitConverter::toBase($entered, $this->factor($item, $unitId));
                if (bccomp($counted, '0', UnitConverter::SCALE) < 0) {
                    throw new InventoryRuleException("الكمية المعدودة للصنف \"{$item->name}\" لا تكون سالبة.");
                }
                $line->forceFill([
                    'unit_id' => $unitId,
                    'entered_quantity' => UnitConverter::toBase($entered, '1'),
                    'counted_quantity' => $counted,
                ])->save();
            }
            $count->update(['notes' => $notes]);

            return $count->fresh();
        });
    }

    public function delete(StockCount $count): void
    {
        DB::transaction(fn () => $this->lockDraft($count)->delete());
    }

    public function post(StockCount $count, User $by): StockCount
    {
        // أسطر الأرصدة الناقصة تُنشأ برصيد صفر قبل المعاملة (INSERT IGNORE داخلها يسبب Deadlock
        // مع FOR UPDATE لدى عمليتين متزامنتين — انظر StockLedger).
        foreach ($count->lines()->whereNotNull('counted_quantity')->pluck('stock_item_id') as $itemId) {
            DB::table('stock_balances')->insertOrIgnore([
                'warehouse_id' => $count->warehouse_id, 'stock_item_id' => $itemId,
                'quantity' => 0, 'avg_cost' => 0, 'updated_at' => now(),
            ]);
        }

        return DB::transaction(function () use ($count, $by) {
            $count = StockCount::whereKey($count->id)->lockForUpdate()->firstOrFail();
            if ($count->status === 'posted') {
                return $count;
            }
            $lines = $count->lines()->whereNotNull('counted_quantity')->orderBy('stock_item_id')->get();
            if ($lines->isEmpty()) {
                throw new InventoryRuleException('لم يُدخل أي معدود في ورقة الجرد.');
            }

            // قفل أسطر الأرصدة بترتيب الصنف (نفس ترتيب StockLedger) حتى لا تتغير بين القراءة والتسوية.
            $balances = DB::table('stock_balances')
                ->where('warehouse_id', $count->warehouse_id)
                ->whereIn('stock_item_id', $lines->pluck('stock_item_id'))
                ->orderBy('stock_item_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('stock_item_id');
            $lastCosts = StockItem::whereIn('id', $lines->pluck('stock_item_id'))->pluck('last_cost', 'id');
            $today = $this->accountingDay->of(now());

            $movementLines = [];
            $owner = [];
            foreach ($lines as $line) {
                $b = $balances[$line->stock_item_id];
                $counted = (string) $line->counted_quantity;
                $expected = (string) $b->quantity;
                // الفرق = المعدود − الرصيد المسجّل: موجب = زيادة، سالب = عجز
                $variance = bcsub($counted, $expected, UnitConverter::SCALE);
                $line->forceFill(['expected_quantity' => $expected, 'variance_quantity' => $variance]);
                if (bccomp($variance, '0', UnitConverter::SCALE) === 0) {
                    $line->forceFill(['unit_cost' => (string) $b->avg_cost, 'variance_value' => '0'])->save();

                    continue;
                }
                $line->save();

                // الزيادة تدخل بمتوسط التكلفة الحالي (أو آخر تكلفة شراء إن كان المتوسط صفراً)؛ العجز يخرج بالمتوسط.
                $inCost = bccomp((string) $b->avg_cost, '0', UnitConverter::SCALE) > 0 ? (string) $b->avg_cost : (string) ($lastCosts[$line->stock_item_id] ?? '0');
                $owner[] = $line;
                $movementLines[] = new StockMovementLine(
                    warehouseId: $count->warehouse_id,
                    stockItemId: $line->stock_item_id,
                    type: 'adjustment',
                    quantity: $variance,
                    unitCost: bccomp($variance, '0', UnitConverter::SCALE) > 0 ? $inCost : null,
                    occurredAt: now(),
                    sourceType: StockCount::SOURCE_TYPE,
                    sourceId: $count->id,
                    idempotencyKey: 'count:'.$count->id.':line:'.$line->id,
                    notes: $count->number,
                );
            }

            $total = '0';
            if ($movementLines !== []) {
                // الرصيد الناتج = المعدود (≥ 0)، فلا يُرفض بسبب سياسة الرصيد السالب.
                $movements = $this->ledger->postMany($movementLines, $by, allowNegative: true);
                foreach ($movements as $k => $m) {
                    $owner[$k]->forceFill(['unit_cost' => $m->unit_cost, 'variance_value' => (string) $m->total_cost, 'movement_id' => $m->id])->save();
                    $total = bcadd($total, (string) $m->total_cost, 6);
                }
            }

            $count->forceFill([
                'status' => 'posted',
                'count_date' => $today,
                'variance_value' => $total,
                'posted_by' => $by->id,
                'posted_at' => now(),
            ])->save();

            return $count;
        }, 3);
    }

    /** رصيد صنف في مخزن في نهاية يوم محاسبي = Σ كميات حركاته حتى ذلك اليوم. */
    public function balanceAt(int $warehouseId, int $stockItemId, string $day): string
    {
        return (string) DB::table('stock_movements')
            ->where('warehouse_id', $warehouseId)->where('stock_item_id', $stockItemId)
            ->where('accounting_day', '<=', $day)
            ->sum('quantity');
    }

    private function assertActive(Warehouse $warehouse): void
    {
        if (! Warehouse::whereKey($warehouse->id)->value('is_active')) {
            throw new InventoryRuleException('المخزن موقوف.');
        }
    }

    private function factor(StockItem $item, int $unitId): string
    {
        if ($unitId === (int) $item->base_unit_id) {
            return '1';
        }
        $alt = $item->units->firstWhere('unit_id', $unitId);
        if (! $alt) {
            throw new InventoryRuleException("الوحدة غير معرّفة للصنف \"{$item->name}\".");
        }

        return (string) $alt->factor;
    }

    private function lockDraft(StockCount $count): StockCount
    {
        $locked = StockCount::whereKey($count->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'draft') {
            throw new InventoryRuleException('الجرد المرحّل لا يُعدَّل ولا يُحذف. صحّحه بجرد جديد.');
        }

        return $locked;
    }
}
