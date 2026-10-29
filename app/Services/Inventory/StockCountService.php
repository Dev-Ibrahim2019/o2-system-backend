<?php

namespace App\Services\Inventory;

use App\Models\StockCount;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * الجرد: ورقة لمخزن ← إدخال المعدود ← ترحيل يسجّل حركة "تسوية جرد" للفرق عبر StockLedger.
 *
 * نوعان:
 *  - full: جرد عام. الفرق مقابل الرصيد المسجّل لحظة الترحيل، والصنف غير المعدود لا يُسوّى.
 *  - daily: جرد القسم اليومي (الإنتاجية اليومية في O2) ليوم محدد. المتبقي الفارغ = صفر (الواجهة
 *    تنبّه قبل الحفظ)، والفرق مقابل الرصيد في نهاية ذلك اليوم المحاسبي، والتسوية تُسجَّل فيه — فيصح
 *    حساب اليوم حتى لو أُدخل الجرد في اليوم التالي.
 * العجز يوزَّع على دفعات الصنف في المخزن (الأقرب انتهاءً أولاً) فتبقى أرصدة الدفعات صحيحة.
 * المرحّل لا يُعدَّل؛ يُصحَّح بجرد جديد.
 */
class StockCountService
{
    public function __construct(
        private StockLedger $ledger,
        private BatchService $batches,
        private ?AccountingDay $accountingDay = null,
    ) {
        $this->accountingDay ??= AccountingDay::fromConfig();
    }

    /** ورقة جرد عام بكل الأصناف المخزنية النشطة (أو تصنيف واحد)، مع الرصيد المسجّل حالياً. */
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

        return $this->createSheet($warehouse, 'full', null, $items->all(), fn ($id) => (string) ($balances[$id] ?? '0'), $notes, $by);
    }

    /**
     * ورقة الجرد اليومي لقسم في يوم (واحدة لكل يوم). الأصناف = ما دخل هذا المخزن يوماً، والمتوقع =
     * رصيد نهاية ذلك اليوم. إن وُجدت الورقة تُرجَع كما هي.
     */
    public function daily(Warehouse $warehouse, string $day, User $by): StockCount
    {
        $existing = StockCount::where('warehouse_id', $warehouse->id)->where('kind', 'daily')->where('business_day', $day)->first();
        if ($existing) {
            return $existing;
        }
        $this->assertActive($warehouse);
        if ($day > $this->accountingDay->of(now())) {
            throw new InventoryRuleException('لا يُجرد يوم لم يبدأ بعد.');
        }

        $items = DB::table('stock_balances as b')
            ->join('stock_items as i', 'i.id', '=', 'b.stock_item_id')
            ->where('b.warehouse_id', $warehouse->id)
            ->where('i.is_stored', true)->where('i.is_active', true)
            ->orderBy('i.name')
            ->pluck('i.id');
        if ($items->isEmpty()) {
            throw new InventoryRuleException('لم يدخل هذا المخزن أي صنف بعد.');
        }

        return $this->createSheet($warehouse, 'daily', $day, $items->all(), fn ($id) => $this->balanceAt($warehouse->id, $id, $day), null, $by);
    }

    /**
     * حفظ المعدود. entered_quantity فارغ = لم يُعدّ (وفي اليومي يُعتبر صفراً عند الترحيل).
     * dissected/marinated: تفصيل الباقي من اللحوم (قسم الشاورما) بالوحدة نفسها المُدخلة.
     *
     * @param  array<int, array{stock_item_id:int, unit_id?:?int, entered_quantity:mixed, dissected?:mixed, marinated?:mixed}>  $lines
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
                    $line->forceFill(['unit_id' => null, 'entered_quantity' => null, 'counted_quantity' => null,
                        'dissected_quantity' => null, 'marinated_quantity' => null])->save();

                    continue;
                }
                $item = $line->stockItem;
                $unitId = (int) ($l['unit_id'] ?? 0) ?: (int) $item->base_unit_id;
                $factor = $this->factor($item, $unitId);
                // المعدود بالوحدة الأساسية = المُدخل × معامل الوحدة (3 كراتين × 10000 = 30000 غ)
                $counted = UnitConverter::toBase($entered, $factor);
                $dissected = isset($l['dissected']) && $l['dissected'] !== '' ? UnitConverter::toBase($l['dissected'], $factor) : null;
                $marinated = isset($l['marinated']) && $l['marinated'] !== '' ? UnitConverter::toBase($l['marinated'], $factor) : null;
                if (bccomp($counted, '0', UnitConverter::SCALE) < 0) {
                    throw new InventoryRuleException("الكمية المعدودة للصنف \"{$item->name}\" لا تكون سالبة.");
                }
                // مشرّح + مبهّر لا يتجاوزان الباقي (بدون تشريح = الباقي − مشرّح − مبهّر)
                if (bccomp(bcadd($dissected ?? '0', $marinated ?? '0', 6), $counted, 6) > 0) {
                    throw new InventoryRuleException("المشرّح والمبهّر من \"{$item->name}\" أكثر من الباقي.");
                }
                $line->forceFill([
                    'unit_id' => $unitId,
                    'entered_quantity' => UnitConverter::toBase($entered, '1'),
                    'counted_quantity' => $counted,
                    'dissected_quantity' => $dissected,
                    'marinated_quantity' => $marinated,
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
        $daily = $count->kind === 'daily';
        // أسطر الأرصدة الناقصة تُنشأ برصيد صفر قبل المعاملة (INSERT IGNORE داخلها يسبب Deadlock
        // مع FOR UPDATE لدى عمليتين متزامنتين — انظر StockLedger).
        $itemIds = $daily ? $count->lines()->pluck('stock_item_id') : $count->lines()->whereNotNull('counted_quantity')->pluck('stock_item_id');
        foreach ($itemIds as $itemId) {
            DB::table('stock_balances')->insertOrIgnore([
                'warehouse_id' => $count->warehouse_id, 'stock_item_id' => $itemId,
                'quantity' => 0, 'avg_cost' => 0, 'updated_at' => now(),
            ]);
        }

        return DB::transaction(function () use ($count, $by, $daily) {
            $count = StockCount::whereKey($count->id)->lockForUpdate()->firstOrFail();
            if ($count->status === 'posted') {
                return $count;
            }
            $lines = $count->lines()
                ->when(! $daily, fn ($q) => $q->whereNotNull('counted_quantity'))
                ->orderBy('stock_item_id')
                ->get();
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
            $day = $daily ? $count->business_day->toDateString() : $today;
            $at = $day === $today ? now() : CarbonImmutable::instance($this->accountingDay->bounds($day)[1])->subSecond();

            $movementLines = [];
            $owner = [];
            foreach ($lines as $line) {
                $b = $balances[$line->stock_item_id];
                // اليومي: الفارغ = صفر، والمقارنة برصيد نهاية اليوم؛ العام: بالرصيد الحالي.
                $counted = $line->counted_quantity !== null ? (string) $line->counted_quantity : '0';
                $expected = $daily ? $this->balanceAt($count->warehouse_id, $line->stock_item_id, $day) : (string) $b->quantity;
                // الفرق = المعدود − الرصيد المسجّل: موجب = زيادة، سالب = عجز
                $variance = bcsub($counted, $expected, UnitConverter::SCALE);
                $line->forceFill(['expected_quantity' => $expected, 'variance_quantity' => $variance, 'counted_quantity' => $counted]);
                if (bccomp($variance, '0', UnitConverter::SCALE) === 0) {
                    $line->forceFill(['unit_cost' => (string) $b->avg_cost, 'variance_value' => '0'])->save();

                    continue;
                }
                $line->save();

                if (bccomp($variance, '0', UnitConverter::SCALE) > 0) {
                    // الزيادة تدخل بمتوسط التكلفة الحالي (أو آخر تكلفة شراء إن كان المتوسط صفراً)، بلا دفعة.
                    $inCost = bccomp((string) $b->avg_cost, '0', UnitConverter::SCALE) > 0 ? (string) $b->avg_cost : (string) ($lastCosts[$line->stock_item_id] ?? '0');
                    $parts = [['batch_id' => null, 'quantity' => $variance, 'cost' => $inCost]];
                } else {
                    $parts = array_map(fn ($p) => [...$p, 'quantity' => bcmul($p['quantity'], '-1', 6), 'cost' => null],
                        $this->batches->allocate($count->warehouse_id, $line->stock_item_id, bcmul($variance, '-1', 6)));
                }
                foreach ($parts as $p => $part) {
                    $owner[] = $line;
                    $movementLines[] = new StockMovementLine(
                        warehouseId: $count->warehouse_id,
                        stockItemId: $line->stock_item_id,
                        type: 'adjustment',
                        quantity: $part['quantity'],
                        unitCost: $part['cost'],
                        occurredAt: $at,
                        sourceType: StockCount::SOURCE_TYPE,
                        sourceId: $count->id,
                        idempotencyKey: 'count:'.$count->id.':line:'.$line->id.($p > 0 ? ':'.$p : ''),
                        notes: $count->number,
                        batchId: $part['batch_id'],
                    );
                }
            }

            $total = '0';
            if ($movementLines !== []) {
                // الرصيد الناتج = المعدود (≥ 0)، فلا يُرفض بسبب سياسة الرصيد السالب.
                $movements = $this->ledger->postMany($movementLines, $by, allowNegative: true);
                $perLine = [];
                foreach ($movements as $k => $m) {
                    $line = $owner[$k];
                    $perLine[$line->id] = [
                        'line' => $line,
                        'value' => bcadd($perLine[$line->id]['value'] ?? '0', (string) $m->total_cost, 6),
                        'movement_id' => $perLine[$line->id]['movement_id'] ?? $m->id,
                        'unit_cost' => $m->unit_cost,
                    ];
                }
                foreach ($perLine as $r) {
                    $r['line']->forceFill(['unit_cost' => $r['unit_cost'], 'variance_value' => $r['value'], 'movement_id' => $r['movement_id']])->save();
                    $total = bcadd($total, $r['value'], 6);
                }
            }

            $count->forceFill([
                'status' => 'posted',
                'count_date' => $day,
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

    /** @param  array<int>  $items */
    private function createSheet(Warehouse $warehouse, string $kind, ?string $day, array $items, callable $expected, ?string $notes, User $by): StockCount
    {
        return DB::transaction(function () use ($warehouse, $kind, $day, $items, $expected, $notes, $by) {
            $count = StockCount::create([
                'warehouse_id' => $warehouse->id, 'kind' => $kind, 'business_day' => $day, 'notes' => $notes, 'created_by' => $by->id,
            ]);
            $count->forceFill(['number' => ($kind === 'daily' ? 'DAY-' : 'CNT-').str_pad((string) $count->id, 6, '0', STR_PAD_LEFT)])->save();
            $count->lines()->createMany(array_map(fn ($id) => ['stock_item_id' => $id, 'expected_quantity' => $expected($id)], $items));

            return $count;
        });
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
