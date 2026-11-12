<?php

namespace App\Services\Inventory;

use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockDraftAllocation;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * الجرد: ورقة لمخزن ← إدخال المعدود ← تثبيت يسجّل حركة "تسوية جرد" للفرق عبر StockLedger.
 * الفرق مقابل الرصيد المسجّل لحظة التثبيت، والصنف غير المعدود لا يُسوّى. المثبّت لا يُعدَّل ولا يُعكس
 * (لا يوجد عكس لتسوية الجرد في النظام)؛ يُصحَّح بجرد جديد.
 *
 * يجوز أكثر من مسودة للمخزن (عدّادون أو رفوف مختلفة) وتُدمج قبل التثبيت: لا تُثبَّت ورقة إذا كانت
 * مسودة أخرى للمخزن نفسه قد عدّت أحد أصنافها، لأن تثبيت كل ورقة وحدها يجعل الثانية تلغي أثر الأولى.
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
        $items = StockItem::query()
            ->where('is_stored', true)
            ->where('is_active', true)
            ->when($categoryId, fn ($q) => $q->whereHas('categories', fn ($c) => $c->where('stock_categories.id', $categoryId)))
            ->orderBy('name')
            ->pluck('id');
        if ($items->isEmpty()) {
            throw new InventoryRuleException('لا توجد أصناف مخزنية لجردها.');
        }

        return $this->newSheet($warehouse, $items, $notes, $by);
    }

    /**
     * ورقة جرد بأصناف محددة (جرد القسم: أصناف مخزن القسم فقط).
     *
     * @param  Collection<int, int>  $itemIds
     */
    public function createFor(Warehouse $warehouse, Collection $itemIds, ?string $notes, User $by): StockCount
    {
        $this->assertActive($warehouse);
        $items = StockItem::whereIn('id', $itemIds)->where('is_stored', true)->orderBy('name')->pluck('id');
        if ($items->isEmpty()) {
            throw new InventoryRuleException('لا توجد أصناف مخزنية لجردها.');
        }

        return $this->newSheet($warehouse, $items, $notes, $by);
    }

    /**
     * عكس جرد معتمد: كل تسوية سجّلها تُعكس بحركة معاكسة (بتكلفتها نفسها)، ويصبح الجرد "معكوساً".
     * الجرد المعتمد لا يُعدَّل؛ هذا هو طريق تصحيحه، ثم يُعمل جرد جديد.
     */
    public function reverse(StockCount $count, string $reason, User $by): StockCount
    {
        return DB::transaction(function () use ($count, $reason, $by) {
            $count = StockCount::whereKey($count->id)->lockForUpdate()->firstOrFail();
            if ($count->status === 'reversed') {
                throw new InventoryRuleException('هذا الجرد معكوس مسبقاً.');
            }
            if ($count->status !== 'posted') {
                throw new InventoryRuleException('لا يُعكس إلا جرد معتمد. المسودة تُعدَّل أو تُحذف.');
            }
            $movements = StockMovement::whereIn('id', $count->lines()->whereNotNull('movement_id')->pluck('movement_id'))
                ->orderBy('stock_item_id')->get();
            foreach ($movements as $movement) {
                // عكس الزيادة قد ينزل بالرصيد تحت الصفر إن صُرف منه بعد الجرد — مثل عكس البيع، لا يُرفض.
                $this->ledger->reverse($movement, "عكس الجرد {$count->number}: {$reason}", $by, allowNegative: true);
            }
            $count->forceFill([
                'status' => 'reversed',
                'reversed_by' => $by->id,
                'reversed_at' => now(),
                'reversal_reason' => mb_substr($reason, 0, 500),
            ])->save();

            return $count->fresh();
        }, 3);
    }

    /** تكرار الكشف: مسودة جديدة للمخزن نفسه بأصناف الورقة نفسها، بالرصيد الحالي وبلا معدود. */
    public function duplicate(StockCount $count, User $by): StockCount
    {
        $warehouse = Warehouse::findOrFail($count->warehouse_id);
        $this->assertActive($warehouse);

        return $this->newSheet($warehouse, $count->lines()->pluck('stock_item_id'), "نسخة من {$count->number}", $by);
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
                    // سعر الزيادة (للوحدة الأساسية) إن أدخله المستخدم؛ فارغ = سعر آخر دفعة عند الاعتماد.
                    'entered_unit_cost' => isset($l['unit_cost']) && $l['unit_cost'] !== '' && $l['unit_cost'] !== null
                        ? UnitConverter::fromBase((string) $l['unit_cost'], $this->factor($item, $unitId)) : null,
                ])->save();
                // توزيع العجز على الدفعات (بالوحدة الأساسية) — فارغ = الأقدم أولاً.
                if (array_key_exists('allocations', $l)) {
                    StockDraftAllocation::where('owner_type', StockDraftAllocation::COUNT_LINE)->where('owner_id', $line->id)->delete();
                    foreach ($l['allocations'] ?? [] as $a) {
                        StockDraftAllocation::create(['owner_type' => StockDraftAllocation::COUNT_LINE, 'owner_id' => $line->id,
                            'layer_id' => (int) $a['layer_id'], 'quantity' => UnitConverter::round((string) $a['quantity'])]);
                    }
                }
            }
            $count->update(['notes' => $notes]);

            return $count->fresh();
        });
    }

    /** إعادة احتساب الكمية بالمخزن لكل سطر من الرصيد الحالي (السعر يُعرض من متوسط التكلفة الحالي). */
    public function recalculate(StockCount $count): StockCount
    {
        return DB::transaction(function () use ($count) {
            $count = $this->lockDraft($count);
            $balances = $this->balances($count->warehouse_id);
            foreach ($count->lines()->get(['id', 'stock_item_id']) as $line) {
                $line->forceFill(['expected_quantity' => (string) ($balances[$line->stock_item_id] ?? '0')])->save();
            }

            return $count->fresh();
        });
    }

    /**
     * أصناف لها رصيد في المخزن وليست في الورقة (أُنشئت بعدها، أو خارج تصنيف الورقة).
     *
     * @return Collection<int, array{id:int, code:string, name:string, quantity:string, unit:?string}>
     */
    public function missingItems(StockCount $count): Collection
    {
        return DB::table('stock_balances as b')
            ->join('stock_items as i', 'i.id', '=', 'b.stock_item_id')
            ->leftJoin('stock_units as u', 'u.id', '=', 'i.base_unit_id')
            ->where('b.warehouse_id', $count->warehouse_id)
            ->where('b.quantity', '!=', 0)
            ->where('i.is_stored', true)
            ->whereNotExists(fn ($q) => $q->from('stock_count_lines as l')->where('l.stock_count_id', $count->id)->whereColumn('l.stock_item_id', 'b.stock_item_id'))
            ->orderBy('i.code')
            ->get(['i.id', 'i.code', 'i.name', 'b.quantity', 'u.name as unit'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'code' => $r->code, 'name' => $r->name, 'quantity' => (string) $r->quantity, 'unit' => $r->unit]);
    }

    /** @param  array<int>  $itemIds */
    public function addLines(StockCount $count, array $itemIds): StockCount
    {
        return DB::transaction(function () use ($count, $itemIds) {
            $count = $this->lockDraft($count);
            $have = $count->lines()->pluck('stock_item_id')->all();
            $items = StockItem::whereIn('id', $itemIds)->where('is_stored', true)->pluck('id')->diff($have);
            $balances = $this->balances($count->warehouse_id);
            $count->lines()->createMany($items->map(fn ($id) => ['stock_item_id' => $id, 'expected_quantity' => (string) ($balances[$id] ?? '0')])->values()->all());

            return $count->fresh();
        });
    }

    /**
     * دمج مسودات المخزن نفسه في هذه الورقة ثم حذفها. الصنف المعدود في أكثر من ورقة تُجمع كمياته
     * (رفوف أو عدّادون مختلفون)، والصنف غير الموجود في الهدف يُضاف إليه.
     *
     * @param  array<int>  $sourceIds
     */
    public function merge(StockCount $target, array $sourceIds): StockCount
    {
        $sourceIds = array_values(array_unique(array_map('intval', $sourceIds)));
        if ($sourceIds === [] || in_array($target->id, $sourceIds, true)) {
            throw new InventoryRuleException('اختر ورقة جرد أخرى للدمج.');
        }

        return DB::transaction(function () use ($target, $sourceIds) {
            // قفل الأوراق بترتيب المعرّف حتى لا يتعارض دمجان متزامنان.
            $all = StockCount::whereIn('id', [...$sourceIds, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $target = $all->get($target->id);
            if (! $target || $target->status !== 'draft' || $all->count() !== count($sourceIds) + 1) {
                throw new InventoryRuleException('الدمج للمسودات فقط.');
            }
            $sources = $all->except($target->id);
            // معرّفات النماذج لا مفاتيح المجموعة: except() في مجموعة Eloquent تعيد ترقيم المفاتيح (0، 1، ...).
            $sourceIds = $sources->modelKeys();
            foreach ($sources as $s) {
                if ($s->status !== 'draft' || $s->warehouse_id !== $target->warehouse_id) {
                    throw new InventoryRuleException("لا يُدمج إلا مسودات المخزن نفسه ({$s->number}).");
                }
            }

            $lines = $target->lines()->with('stockItem:id,base_unit_id')->get()->keyBy('stock_item_id');
            $incoming = StockCountLine::with('stockItem:id,base_unit_id')->whereIn('stock_count_id', $sourceIds)->orderBy('id')->get();
            foreach ($incoming as $src) {
                $line = $lines->get($src->stock_item_id);
                if (! $line) {
                    $line = $target->lines()->create([
                        'stock_item_id' => $src->stock_item_id,
                        'expected_quantity' => (string) $src->expected_quantity,
                    ]);
                    $line->setRelation('stockItem', $src->stockItem);
                    $lines->put($src->stock_item_id, $line);
                }
                if ($src->counted_quantity === null) {
                    continue;
                }
                if ($line->counted_quantity === null) {
                    $line->forceFill(['unit_id' => $src->unit_id, 'entered_quantity' => $src->entered_quantity, 'counted_quantity' => $src->counted_quantity])->save();

                    continue;
                }
                $counted = bcadd((string) $line->counted_quantity, (string) $src->counted_quantity, UnitConverter::SCALE);
                $sameUnit = (int) $line->unit_id === (int) $src->unit_id;
                $line->forceFill([
                    // بوحدتين مختلفتين يُحفظ المجموع بالوحدة الأساسية.
                    'unit_id' => $sameUnit ? $line->unit_id : $line->stockItem->base_unit_id,
                    'entered_quantity' => $sameUnit ? bcadd((string) $line->entered_quantity, (string) $src->entered_quantity, UnitConverter::SCALE) : $counted,
                    'counted_quantity' => $counted,
                ])->save();
            }

            $merged = $sources->pluck('number')->implode('، ');
            $target->update(['notes' => mb_substr(trim(($target->notes ? $target->notes.' — ' : '')."دُمج معه: {$merged}"), 0, 1000)]);
            StockCount::whereIn('id', $sourceIds)->delete();

            return $target->fresh();
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
            if ($count->status === 'reversed') {
                throw new InventoryRuleException('هذا الجرد معكوس ولا يُعتمد مرة أخرى. اعمل جرداً جديداً.');
            }
            $lines = $count->lines()->whereNotNull('counted_quantity')->orderBy('stock_item_id')->get();
            if ($lines->isEmpty()) {
                throw new InventoryRuleException('لم يُدخل أي معدود في ورقة الجرد.');
            }
            $clash = StockCount::query()
                ->where('warehouse_id', $count->warehouse_id)->where('status', 'draft')->whereKeyNot($count->id)
                ->whereHas('lines', fn ($q) => $q->whereNotNull('counted_quantity')->whereIn('stock_item_id', $lines->pluck('stock_item_id')))
                ->value('number');
            if ($clash) {
                throw new InventoryRuleException("مسودة الجرد {$clash} للمخزن نفسه فيها معدود لأصناف من هذه الورقة. ادمجهما أولاً حتى لا يُسوّى الصنف مرتين.");
            }

            // قفل أسطر الأرصدة بترتيب الصنف (نفس ترتيب StockLedger) حتى لا تتغير بين القراءة والتسوية.
            $balances = DB::table('stock_balances')
                ->where('warehouse_id', $count->warehouse_id)
                ->whereIn('stock_item_id', $lines->pluck('stock_item_id'))
                ->orderBy('stock_item_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('stock_item_id');
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

                // الزيادة دفعة جديدة بالسعر الذي أدخله المستخدم، وإلا بسعر آخر دفعة للصنف؛ العجز يُصرف من الدفعات
                // (توزيع المستخدم إن وُجد، وإلا الأقدم أولاً).
                $surplus = bccomp($variance, '0', UnitConverter::SCALE) > 0;
                $inCost = $line->entered_unit_cost !== null
                    ? (string) $line->entered_unit_cost
                    : $this->ledger->layers()->latestCost((int) $count->warehouse_id, (int) $line->stock_item_id);
                $owner[] = $line;
                $movementLines[] = new StockMovementLine(
                    warehouseId: $count->warehouse_id,
                    stockItemId: $line->stock_item_id,
                    type: 'adjustment',
                    quantity: $variance,
                    unitCost: $surplus ? $inCost : null,
                    occurredAt: now(),
                    sourceType: StockCount::SOURCE_TYPE,
                    sourceId: $count->id,
                    idempotencyKey: 'count:'.$count->id.':line:'.$line->id,
                    notes: $count->number,
                    allocations: $surplus ? null : $this->countAllocations($line->id),
                    sourceLineId: $line->id,
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

    /** @param  Collection<int, int>  $itemIds */
    private function newSheet(Warehouse $warehouse, Collection $itemIds, ?string $notes, User $by): StockCount
    {
        $balances = $this->balances($warehouse->id);

        return DB::transaction(function () use ($warehouse, $itemIds, $balances, $notes, $by) {
            $count = StockCount::create(['warehouse_id' => $warehouse->id, 'kind' => 'full', 'notes' => $notes, 'created_by' => $by->id]);
            $count->forceFill(['number' => 'CNT-'.str_pad((string) $count->id, 6, '0', STR_PAD_LEFT)])->save();
            $count->lines()->createMany($itemIds->unique()->map(fn ($id) => ['stock_item_id' => $id, 'expected_quantity' => (string) ($balances[$id] ?? '0')])->values()->all());

            // fresh: الحالة (draft) قيمة افتراضية من قاعدة البيانات لا تظهر في النموذج المنشأ للتو.
            return $count->fresh();
        });
    }

    /** @return Collection<int, string> الرصيد الحالي لكل صنف في المخزن */
    private function balances(int $warehouseId): Collection
    {
        return DB::table('stock_balances')->where('warehouse_id', $warehouseId)->pluck('quantity', 'stock_item_id');
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

    /** @return array<int, array{layer_id:int, quantity:string}>|null توزيع عجز سطر الجرد على الدفعات (null = الأقدم أولاً). */
    private function countAllocations(int $lineId): ?array
    {
        $rows = StockDraftAllocation::where('owner_type', StockDraftAllocation::COUNT_LINE)->where('owner_id', $lineId)->orderBy('id')->get();

        return $rows->isEmpty() ? null : $rows->map(fn ($r) => ['layer_id' => (int) $r->layer_id, 'quantity' => (string) $r->quantity])->all();
    }

    private function lockDraft(StockCount $count): StockCount
    {
        $locked = StockCount::whereKey($count->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'draft') {
            throw new InventoryRuleException('الجرد المثبّت لا يُعدَّل ولا يُحذف. صحّحه بجرد جديد.');
        }

        return $locked;
    }
}
