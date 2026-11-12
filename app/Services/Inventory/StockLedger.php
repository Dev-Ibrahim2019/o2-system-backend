<?php

namespace App\Services\Inventory;

use App\Models\InventorySetting;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * نقطة الدخول الوحيدة لتغيير المخزون (القاعدة 1).
 *
 * كل عملية ذرّية (القاعدة 3): تُقفل أسطر الأرصدة المعنية (SELECT ... FOR UPDATE) بترتيب ثابت
 * (المخزن ثم الصنف) حتى لا يحدث Deadlock بين عمليتين متزامنتين، ثم تُسجَّل كل حركة ويُحدَّث
 * رصيدها داخل المعاملة نفسها. إن فشل سطر واحد لا يُسجَّل شيء.
 *
 * التكلفة بالدفعات (StockLayerService) لا بالمتوسط المرجّح: الوارد ينشئ دفعة بسعره، والصادر يأخذ تكلفة الدفعات
 * التي خرج منها (توزيع يدوي أو الأقدم أولاً). avg_cost في الأرصدة والحركات للعرض فقط = قيمة الدفعات ÷ الكمية.
 *
 * الـ Triggers في القاعدة (migration 2027_03_02_000001 و 2027_03_13_000001) ترفض أي تعديل للرصيد لا يمر من هنا.
 */
class StockLedger
{
    private StockLayerService $layers;

    public function __construct(private ?AccountingDay $accountingDay = null)
    {
        $this->accountingDay ??= AccountingDay::fromConfig();
        $this->layers = new StockLayerService($this);
    }

    public function layers(): StockLayerService
    {
        return $this->layers;
    }

    /**
     * تسجيل حركة واحدة.
     *
     * @param  bool|null  $allowNegative  null = حسب إعدادات المخزون (يسمح بالنقص المعلّق إن لم تكفِ الدفعات)
     */
    public function post(StockMovementLine $line, ?User $by = null, ?bool $allowNegative = null): StockMovement
    {
        return $this->postMany([$line], $by, $allowNegative)[0];
    }

    /**
     * تسجيل عدة حركات كعملية واحدة: تنجح كلها أو لا يُسجَّل شيء.
     * سطر بمفتاح منع تكرار مسجّل مسبقاً لا يُعاد تسجيله، ويُرجَع سجله الأصلي (القاعدة 4).
     *
     * @param  array<StockMovementLine>  $lines
     * @return array<StockMovement> بنفس ترتيب الأسطر المُدخلة
     */
    public function postMany(array $lines, ?User $by = null, ?bool $allowNegative = null): array
    {
        if ($lines === []) {
            return [];
        }

        return $this->apply($this->resolve($lines), $by, $allowNegative);
    }

    /**
     * عكس حركة مرحّلة (القرار 8): حركة بنفس النوع وكمية معاكسة. الصادر تعود كمياته لنفس الدفعات التي خرج منها
     * وبتكلفتها، والوارد تُلغى دفعته — فقط إن لم يُصرف منها شيء. الحركة لا تُعكس إلا مرة، والعكسية لا تُعكس.
     */
    public function reverse(StockMovement $original, string $reason, ?User $by = null, ?bool $allowNegative = null): StockMovement
    {
        return DB::transaction(function () use ($original, $reason, $by, $allowNegative) {
            // قفل الحركة الأصلية يمنع عكسها مرتين في اللحظة نفسها (والقيد الفريد خط دفاع ثانٍ).
            $locked = StockMovement::whereKey($original->id)->lockForUpdate()->firstOrFail();

            if ($locked->reversal_of_id !== null) {
                throw new InventoryRuleException('لا يمكن عكس حركة عكسية.');
            }
            if (StockMovement::where('reversal_of_id', $locked->id)->exists()) {
                throw new InventoryRuleException('هذه الحركة معكوسة مسبقاً.');
            }

            $entry = [
                'warehouse_id' => (int) $locked->warehouse_id,
                'stock_item_id' => (int) $locked->stock_item_id,
                'item_name' => null,
                'type' => $locked->type,
                'quantity' => bcmul((string) $locked->quantity, '-1', UnitConverter::SCALE),
                'unit_cost' => null,
                'entered_unit_id' => null,
                'entered_quantity' => null,
                'occurred_at' => now(),
                'source_type' => $locked->source_type,
                'source_id' => $locked->source_id,
                'idempotency_key' => null,
                'notes' => mb_substr($reason, 0, 500),
                'reversal_of_id' => (int) $locked->id,
                'reverse_of' => $locked,
                'allocations' => null,
                'source_line_id' => null,
                'layer' => null,
                'mirror_of' => null,
            ];

            return $this->apply([$entry], $by, $allowNegative)[0];
        }, 3);
    }

    /**
     * حركة تصحيح تكلفة (قيمة فقط، كمية صفر) — تسوية سعر أو نقص معلّق. بنوع الحركة المصحَّحة ومرتبطة بها
     * (adjusts_movement_id)، أو بنوع "إعادة تقييم". $valueAfter = قيمة الرصيد بعد التصحيح (لمتوسط العرض).
     */
    public function postValueAdjustment(int $warehouseId, int $itemId, string $type, string $value, ?int $adjustsMovementId,
        ?string $sourceType, ?int $sourceId, ?string $notes, ?User $by, string $valueAfter): ?StockMovement
    {
        if (bccomp($value, '0', UnitConverter::SCALE) === 0) {
            return null;
        }
        $balance = $this->lockPair($warehouseId, $itemId);
        if ($adjustsMovementId === null) {
            $type = 'revaluation';
        }
        $at = now();
        $movement = StockMovement::create([
            'warehouse_id' => $warehouseId,
            'stock_item_id' => $itemId,
            'type' => $type,
            'quantity' => '0',
            'unit_cost' => '0',
            'total_cost' => UnitConverter::round($value),
            'balance_after' => (string) $balance->quantity,
            'avg_cost_after' => $this->avg($valueAfter, (string) $balance->quantity),
            'occurred_at' => $at,
            'accounting_day' => $this->accountingDay->of($at),
            'adjusts_movement_id' => $adjustsMovementId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'notes' => $notes !== null ? mb_substr($notes, 0, 500) : null,
            'created_by' => $by?->id,
        ]);
        DB::table('stock_balances')->where('warehouse_id', $warehouseId)->where('stock_item_id', $itemId)->update([
            'avg_cost' => $movement->avg_cost_after,
            'last_movement_id' => $movement->id,
            'updated_at' => now(),
        ]);

        return $movement;
    }

    /** قفل سطر رصيد صنف في مخزن (يُنشأ إن لم يوجد) داخل المعاملة الجارية. */
    public function lockPair(int $warehouseId, int $itemId): object
    {
        $row = $this->lockBalance($warehouseId, $itemId);
        if ($row === null) {
            $this->ensureBalanceRows([[$warehouseId, $itemId]]);
            $row = $this->lockBalance($warehouseId, $itemId);
        }

        return $row;
    }

    /**
     * التحقق من الأسطر وتحويلها للوحدة الأساسية.
     *
     * @param  array<StockMovementLine>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function resolve(array $lines): array
    {
        $items = StockItem::with('units')
            ->whereIn('id', array_unique(array_map(fn (StockMovementLine $l) => $l->stockItemId, $lines)))
            ->get()
            ->keyBy('id');
        $warehouseIds = Warehouse::whereIn('id', array_unique(array_map(fn (StockMovementLine $l) => $l->warehouseId, $lines)))
            ->pluck('id')
            ->flip();

        $entries = [];
        foreach ($lines as $line) {
            $item = $items->get($line->stockItemId);
            if (! $item) {
                throw new InventoryRuleException('الصنف المخزني غير موجود.');
            }
            if (! isset($warehouseIds[$line->warehouseId])) {
                throw new InventoryRuleException('المخزن غير موجود.');
            }
            if (! $item->is_stored) {
                throw new InventoryRuleException("الصنف \"{$item->name}\" لا يخضع للتخزين، فلا تُسجَّل له حركات.");
            }
            $direction = StockMovement::TYPES[$line->type]['direction'] ?? null;
            if ($direction === null || $direction === 'value') {
                throw new InvalidArgumentException("نوع حركة غير معروف: {$line->type}");
            }

            // معامل الوحدة المُدخلة: 1 للوحدة الأساسية، وإلا من الوحدات البديلة المعرّفة للصنف.
            $unitId = $line->unitId ?? (int) $item->base_unit_id;
            if ($unitId === (int) $item->base_unit_id) {
                $factor = '1';
            } else {
                $alt = $item->units->firstWhere('unit_id', $unitId);
                if (! $alt) {
                    throw new InventoryRuleException("الوحدة المختارة غير معرّفة للصنف \"{$item->name}\".");
                }
                $factor = (string) $alt->factor;
            }

            // الكمية بالوحدة الأساسية = الكمية المُدخلة × معامل الوحدة
            $quantity = UnitConverter::toBase($line->quantity, $factor);
            $sign = bccomp($quantity, '0', UnitConverter::SCALE);
            if ($sign === 0) {
                throw new InventoryRuleException('كمية الحركة لا يمكن أن تكون صفراً.');
            }
            if (($direction === 'in' && $sign < 0) || ($direction === 'out' && $sign > 0)) {
                throw new InvalidArgumentException("اتجاه الكمية لا يطابق نوع الحركة {$line->type}.");
            }

            $unitCost = null;
            if ($sign > 0) {
                if ($line->mirrorOf === null) {
                    if ($line->unitCost === null) {
                        throw new InventoryRuleException("تكلفة الوحدة مطلوبة لوارد الصنف \"{$item->name}\".");
                    }
                    // تكلفة الوحدة الأساسية = تكلفة الوحدة المُدخلة ÷ معامل الوحدة
                    // مثال: الكيلو بـ 40 ₪ والوحدة الأساسية غرام (معامل 1000) → 0.04 ₪ للغرام
                    $unitCost = UnitConverter::fromBase($line->unitCost, $factor);
                    if (bccomp($unitCost, '0', UnitConverter::SCALE) < 0) {
                        throw new InventoryRuleException('تكلفة الوحدة لا يمكن أن تكون سالبة.');
                    }
                }
            } elseif ($line->unitCost !== null) {
                // الصادر يأخذ تكلفة الدفعات التي خرج منها؛ لا يحدد تكلفته بنفسه.
                throw new InvalidArgumentException('الحركة الصادرة لا تحدد تكلفة؛ تخرج بتكلفة دفعاتها.');
            }

            $entries[] = [
                'warehouse_id' => $line->warehouseId,
                'stock_item_id' => $line->stockItemId,
                'item_name' => $item->name,
                'type' => $line->type,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'entered_unit_id' => $unitId,
                'entered_quantity' => UnitConverter::toBase($line->quantity, '1'),
                'occurred_at' => $line->occurredAt ?? now(),
                'source_type' => $line->sourceType,
                'source_id' => $line->sourceId,
                'idempotency_key' => $line->idempotencyKey,
                'notes' => $line->notes !== null ? mb_substr($line->notes, 0, 500) : null,
                'reversal_of_id' => null,
                'reverse_of' => null,
                'allocations' => $line->allocations,
                'source_line_id' => $line->sourceLineId,
                'layer' => $line->layer,
                'mirror_of' => $line->mirrorOf,
            ];
        }

        return $entries;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<StockMovement>
     */
    private function apply(array $entries, ?User $by, ?bool $allowNegative): array
    {
        $allowNegative ??= InventorySetting::current()->allow_negative_stock;

        $pairs = [];
        foreach ($entries as $e) {
            $pairs[$e['warehouse_id'].':'.$e['stock_item_id']] = [$e['warehouse_id'], $e['stock_item_id']];
        }
        uasort($pairs, fn ($a, $b) => $a <=> $b);

        // أسطر الأرصدة الناقصة تُنشأ برصيد صفر قبل المعاملة: خارج معاملة يُثبَّت كل إدخال فوراً
        // ولا يبقى ممسكاً بقفل مشترك. (INSERT IGNORE على سطر موجود يأخذ قفلاً مشتركاً عليه؛ لو
        // نُفِّذ داخل المعاملة لتعارض مع FOR UPDATE لدى عمليتين متزامنتين وأنتج Deadlock.)
        $this->ensureBalanceRows($pairs);

        // إعادة المحاولة عند Deadlock (حتى 3 مرات): المعاملة تُعاد كاملة من أرصدة مقفلة جديدة،
        // فلا يتكرر خصم ولا يضيع.
        return DB::transaction(function () use ($entries, $pairs, $by, $allowNegative) {
            // قفل أسطر الأرصدة بترتيب ثابت (المخزن ثم الصنف) حتى لا تنتظر عمليتان بعضهما بالتبادل.
            foreach ($pairs as [$warehouseId, $itemId]) {
                $this->lockPair($warehouseId, $itemId);
            }

            $result = [];
            foreach ($entries as $i => $e) {
                // منع التكرار: يُفحص بعد القفل حتى لا تمر نسختان متزامنتان من السطر نفسه.
                if ($e['idempotency_key'] !== null) {
                    $existing = StockMovement::where('idempotency_key', $e['idempotency_key'])->first();
                    if ($existing) {
                        $result[$i] = $existing;

                        continue;
                    }
                }

                $w = (int) $e['warehouse_id'];
                $item = (int) $e['stock_item_id'];
                $balance = $this->lockPair($w, $item);
                $qtyBefore = (string) $balance->quantity;
                $valueBefore = $this->layers->value($w, $item);
                $quantity = $e['quantity'];
                $incoming = bccomp($quantity, '0', UnitConverter::SCALE) > 0;

                // خطة الدفعات وقيمة الحركة (بإشارة الكمية).
                $plan = null;
                $mirror = null;
                if ($e['reverse_of'] !== null) {
                    $plan = $this->layers->planReverse($e['reverse_of']);
                    $total = $plan['total'];
                } elseif ($incoming) {
                    if ($e['mirror_of'] !== null) {
                        $mirror = $this->layers->planMirror((int) $e['mirror_of']);
                        if (bccomp(array_reduce($mirror['parts'], fn ($c, $p) => bcadd($c, $p['quantity'], UnitConverter::SCALE), '0'), $quantity, UnitConverter::SCALE) !== 0) {
                            throw new InventoryRuleException('كمية الوارد لا تطابق كمية الصادر المنسوخة دفعاته.');
                        }
                        $total = $mirror['total'];
                    } else {
                        $total = UnitConverter::round(bcmul($quantity, $e['unit_cost'], UnitConverter::SCALE + 6));
                    }
                } else {
                    $name = $e['item_name'] ?? StockItem::whereKey($item)->value('name');
                    $plan = $this->layers->planIssue($w, $item, bcmul($quantity, '-1', UnitConverter::SCALE), $e['allocations'],
                        (bool) $allowNegative, (string) $name);
                    $total = bcmul($plan['total'], '-1', UnitConverter::SCALE);
                }

                $qtyAfter = bcadd($qtyBefore, $quantity, UnitConverter::SCALE);
                $valueAfter = bcadd($valueBefore, $total, UnitConverter::SCALE);
                // تكلفة الوحدة = قيمة الحركة ÷ كميتها (الوارد: سعره؛ الصادر: متوسط دفعاته هو، لا متوسط المخزن)
                $unitCost = UnitConverter::round(bcdiv(ltrim($total, '-'), ltrim($quantity, '-'), UnitConverter::SCALE + 6));

                $movement = StockMovement::create([
                    'warehouse_id' => $w,
                    'stock_item_id' => $item,
                    'type' => $e['type'],
                    'quantity' => $quantity,
                    'unit_cost' => $e['unit_cost'] !== null && $e['reverse_of'] === null ? UnitConverter::round($e['unit_cost']) : $unitCost,
                    'total_cost' => UnitConverter::round($total),
                    'balance_after' => $qtyAfter,
                    'avg_cost_after' => $this->avg($valueAfter, $qtyAfter),
                    'entered_unit_id' => $e['entered_unit_id'],
                    'entered_quantity' => $e['entered_quantity'],
                    'occurred_at' => $e['occurred_at'],
                    'accounting_day' => $this->accountingDay->of($e['occurred_at']),
                    'reversal_of_id' => $e['reversal_of_id'],
                    'source_type' => $e['source_type'],
                    'source_id' => $e['source_id'],
                    'idempotency_key' => $e['idempotency_key'],
                    'notes' => $e['notes'],
                    'created_by' => $by?->id,
                ]);

                DB::table('stock_balances')
                    ->where('warehouse_id', $w)
                    ->where('stock_item_id', $item)
                    ->update([
                        'quantity' => $qtyAfter,
                        'avg_cost' => $movement->avg_cost_after,
                        'last_movement_id' => $movement->id,
                        'updated_at' => now(),
                    ]);

                // الدفعات بعد الحركة (تسوية النقص قد تضيف حركات تصحيح بعدها).
                if ($e['reverse_of'] !== null) {
                    $this->layers->applyReverse($movement, $plan, $by);
                } elseif ($incoming) {
                    $this->layers->receive($movement, $e['layer'], $mirror, $by);
                } else {
                    $this->layers->applyIssue($movement, $plan, $e['source_line_id']);
                }

                $result[$i] = $movement;
            }

            ksort($result);

            return array_values($result);
        }, 3);
    }

    /** متوسط العرض = القيمة ÷ الكمية (صفر إن كانت الكمية صفراً). لا يدخل في حساب أي تكلفة. */
    private function avg(string $value, string $quantity): string
    {
        if (bccomp($quantity, '0', UnitConverter::SCALE) === 0) {
            return '0';
        }
        $avg = bcdiv($value, $quantity, UnitConverter::SCALE + 6);

        return bccomp($avg, '0', UnitConverter::SCALE) < 0 ? '0' : UnitConverter::round($avg);
    }

    /** @param  array<array{0: int, 1: int}>  $pairs */
    private function ensureBalanceRows(array $pairs): void
    {
        foreach ($pairs as [$warehouseId, $itemId]) {
            $exists = DB::table('stock_balances')
                ->where('warehouse_id', $warehouseId)
                ->where('stock_item_id', $itemId)
                ->exists();
            if (! $exists) {
                DB::table('stock_balances')->insertOrIgnore([
                    'warehouse_id' => $warehouseId,
                    'stock_item_id' => $itemId,
                    'quantity' => 0,
                    'avg_cost' => 0,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function lockBalance(int $warehouseId, int $itemId): ?object
    {
        return DB::table('stock_balances')
            ->where('warehouse_id', $warehouseId)
            ->where('stock_item_id', $itemId)
            ->lockForUpdate()
            ->first();
    }
}
