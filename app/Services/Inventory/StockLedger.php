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
 * الـ Triggers في القاعدة (migration 2027_03_02_000001) ترفض أي تعديل للرصيد لا يمر من هنا.
 */
class StockLedger
{
    public function __construct(private ?AccountingDay $accountingDay = null)
    {
        $this->accountingDay ??= AccountingDay::fromConfig();
    }

    /**
     * تسجيل حركة واحدة.
     *
     * @param  bool|null  $allowNegative  null = حسب إعدادات المخزون
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
     * عكس حركة مرحّلة: حركة بنفس النوع وكمية معاكسة وبتكلفة الحركة الأصلية نفسها (القرار 8).
     * الحركة لا تُعكس إلا مرة واحدة، والحركة العكسية لا تُعكس.
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
                // كمية العكس = − كمية الحركة الأصلية، بتكلفة الوحدة الأصلية نفسها
                'quantity' => bcmul((string) $locked->quantity, '-1', UnitConverter::SCALE),
                'unit_cost' => (string) $locked->unit_cost,
                'entered_unit_id' => null,
                'entered_quantity' => null,
                'occurred_at' => now(),
                'source_type' => $locked->source_type,
                'source_id' => $locked->source_id,
                'idempotency_key' => null,
                'notes' => mb_substr($reason, 0, 500),
                'reversal_of_id' => (int) $locked->id,
                'batch_id' => $locked->batch_id ? (int) $locked->batch_id : null,
            ];

            return $this->apply([$entry], $by, $allowNegative)[0];
        }, 3);
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
            if ($direction === null) {
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
                if ($line->unitCost === null) {
                    throw new InventoryRuleException("تكلفة الوحدة مطلوبة لوارد الصنف \"{$item->name}\".");
                }
                // تكلفة الوحدة الأساسية = تكلفة الوحدة المُدخلة ÷ معامل الوحدة
                // مثال: الكيلو بـ 40 ₪ والوحدة الأساسية غرام (معامل 1000) → 0.04 ₪ للغرام
                $unitCost = UnitConverter::fromBase($line->unitCost, $factor);
                if (bccomp($unitCost, '0', UnitConverter::SCALE) < 0) {
                    throw new InventoryRuleException('تكلفة الوحدة لا يمكن أن تكون سالبة.');
                }
            } elseif ($line->unitCost !== null) {
                // الصادر يخرج دائماً بمتوسط التكلفة؛ التكلفة المحددة للصادر حكر على الحركة العكسية.
                throw new InvalidArgumentException('الحركة الصادرة لا تحدد تكلفة؛ تخرج بمتوسط التكلفة.');
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
                'batch_id' => $line->batchId,
            ];
        }

        // الدفعة يجب أن تكون للصنف نفسه.
        $batchIds = array_filter(array_column($entries, 'batch_id'));
        if ($batchIds !== []) {
            $owners = DB::table('stock_batches')->whereIn('id', $batchIds)->pluck('stock_item_id', 'id');
            foreach ($entries as $e) {
                if ($e['batch_id'] && (int) ($owners[$e['batch_id']] ?? 0) !== (int) $e['stock_item_id']) {
                    throw new InventoryRuleException("الدفعة المختارة لا تخص الصنف \"{$e['item_name']}\".");
                }
            }
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

        // أرصدة الدفعات بالطريقة نفسها: تُنشأ قبل المعاملة، وتُقفل داخلها بترتيب ثابت بعد أرصدة الأصناف.
        $batchPairs = [];
        foreach ($entries as $e) {
            if (! empty($e['batch_id'])) {
                $batchPairs[$e['batch_id'].':'.$e['warehouse_id']] = [(int) $e['batch_id'], (int) $e['warehouse_id']];
            }
        }
        uasort($batchPairs, fn ($a, $b) => $a <=> $b);
        $this->ensureBatchRows($batchPairs);

        // إعادة المحاولة عند Deadlock (حتى 3 مرات): المعاملة تُعاد كاملة من أرصدة مقفلة جديدة،
        // فلا يتكرر خصم ولا يضيع.
        return DB::transaction(function () use ($entries, $pairs, $batchPairs, $by, $allowNegative) {
            // قفل أسطر الأرصدة بترتيب ثابت (المخزن ثم الصنف) حتى لا تنتظر عمليتان بعضهما بالتبادل.
            $balances = [];
            foreach ($pairs as $key => [$warehouseId, $itemId]) {
                $row = $this->lockBalance($warehouseId, $itemId);
                if ($row === null) {
                    // داخل معاملة المستدعي لم يُثبَّت السطر بعد — يُنشأ هنا ثم يُقفل.
                    $this->ensureBalanceRows([[$warehouseId, $itemId]]);
                    $row = $this->lockBalance($warehouseId, $itemId);
                }
                $balances[$key] = ['quantity' => (string) $row->quantity, 'avg_cost' => (string) $row->avg_cost];
            }

            $batchBalances = [];
            foreach ($batchPairs as $bkey => [$batchId, $warehouseId]) {
                $row = DB::table('stock_batch_balances')->where('batch_id', $batchId)->where('warehouse_id', $warehouseId)->lockForUpdate()->first();
                if ($row === null) {
                    $this->ensureBatchRows([[$batchId, $warehouseId]]);
                    $row = DB::table('stock_batch_balances')->where('batch_id', $batchId)->where('warehouse_id', $warehouseId)->lockForUpdate()->first();
                }
                $batchBalances[$bkey] = (string) $row->quantity;
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

                $key = $e['warehouse_id'].':'.$e['stock_item_id'];
                $before = $balances[$key];
                $after = CostCalculator::apply($before['quantity'], $before['avg_cost'], $e['quantity'], $e['unit_cost']);

                $isOutgoing = bccomp($e['quantity'], '0', UnitConverter::SCALE) < 0;
                if ($isOutgoing && ! $allowNegative && bccomp($after['quantity'], '0', UnitConverter::SCALE) < 0) {
                    $name = $e['item_name'] ?? StockItem::whereKey($e['stock_item_id'])->value('name');
                    throw new InventoryRuleException(sprintf(
                        'الرصيد غير كافٍ للصنف "%s": المتاح %s والمطلوب %s (بالوحدة الأساسية).',
                        $name,
                        self::trim($before['quantity']),
                        self::trim(bcmul($e['quantity'], '-1', UnitConverter::SCALE)),
                    ));
                }

                // الصادر من دفعة لا يتجاوز رصيدها في هذا المخزن — مهما كانت سياسة الرصيد السالب.
                $bkey = ! empty($e['batch_id']) ? $e['batch_id'].':'.$e['warehouse_id'] : null;
                if ($bkey !== null) {
                    $batchAfter = bcadd($batchBalances[$bkey], $e['quantity'], UnitConverter::SCALE);
                    if (bccomp($batchAfter, '0', UnitConverter::SCALE) < 0) {
                        $code = DB::table('stock_batches')->where('id', $e['batch_id'])->value('code');
                        throw new InventoryRuleException(sprintf(
                            'رصيد الدفعة %s غير كافٍ: المتاح %s والمطلوب %s (بالوحدة الأساسية).',
                            $code,
                            self::trim($batchBalances[$bkey]),
                            self::trim(bcmul($e['quantity'], '-1', UnitConverter::SCALE)),
                        ));
                    }
                }

                $movement = StockMovement::create([
                    'batch_id' => $e['batch_id'] ?? null,
                    'warehouse_id' => $e['warehouse_id'],
                    'stock_item_id' => $e['stock_item_id'],
                    'type' => $e['type'],
                    'quantity' => $e['quantity'],
                    'unit_cost' => $after['unit_cost'],
                    'total_cost' => $after['total_cost'],
                    'balance_after' => $after['quantity'],
                    'avg_cost_after' => $after['avg_cost'],
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
                    ->where('warehouse_id', $e['warehouse_id'])
                    ->where('stock_item_id', $e['stock_item_id'])
                    ->update([
                        'quantity' => $after['quantity'],
                        'avg_cost' => $after['avg_cost'],
                        'last_movement_id' => $movement->id,
                        'updated_at' => now(),
                    ]);

                if ($bkey !== null) {
                    $batchBalances[$bkey] = bcadd($batchBalances[$bkey], $e['quantity'], UnitConverter::SCALE);
                    DB::table('stock_batch_balances')
                        ->where('batch_id', $e['batch_id'])
                        ->where('warehouse_id', $e['warehouse_id'])
                        ->update([
                            'quantity' => $batchBalances[$bkey],
                            // أول وصول للدفعة لهذا المخزن (مدة التخزين في المعرض تُحسب منه).
                            'arrived_at' => DB::raw('COALESCE(arrived_at, '.DB::getPdo()->quote(
                                \Carbon\CarbonImmutable::instance($e['occurred_at'])->utc()->toDateTimeString()).')'),
                            'updated_at' => now(),
                        ]);
                }

                $balances[$key] = ['quantity' => $after['quantity'], 'avg_cost' => $after['avg_cost']];
                $result[$i] = $movement;
            }

            ksort($result);

            return array_values($result);
        }, 3);
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

    /** @param  array<array{0: int, 1: int}>  $pairs  [batch_id, warehouse_id] */
    private function ensureBatchRows(array $pairs): void
    {
        foreach ($pairs as [$batchId, $warehouseId]) {
            $exists = DB::table('stock_batch_balances')->where('batch_id', $batchId)->where('warehouse_id', $warehouseId)->exists();
            if (! $exists) {
                DB::table('stock_batch_balances')->insertOrIgnore([
                    'batch_id' => $batchId, 'warehouse_id' => $warehouseId, 'quantity' => 0, 'updated_at' => now(),
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

    /** عرض رقم bcmath بلا أصفار زائدة في رسائل الخطأ. */
    private static function trim(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }
}
