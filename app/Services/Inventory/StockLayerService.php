<?php

namespace App\Services\Inventory;

use App\Models\StockLayer;
use App\Models\StockLayerAllocation;
use App\Models\StockLayerRevaluation;
use App\Models\StockLayerShortage;
use App\Models\StockMovement;
use App\Models\StockSalePosting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * محرك الدفعات (Cost Layers) — يعمل داخل معاملة StockLedger وبعد قفل سطر الرصيد للصنف في المخزن، فلا تصل له
 * عمليتان على الصنف نفسه في المخزن نفسه معاً. أسطر الدفعات تُقفل أيضاً (FOR UPDATE مرتبة حسب id) كخط دفاع ثانٍ،
 * والقيد CHECK في القاعدة يمنع المتبقي السالب تحت أي ظرف.
 *
 * - الوارد ينشئ دفعة بسعره، ثم يسوّي النقص المعلّق في المخزن نفسه (الأقدم أولاً).
 * - الصادر يُوزَّع على الدفعات: التوزيع اليدوي (التحديد المعيّن) إن وُجد، والباقي بالأقدم أولاً (FIFO).
 *   ما لا تغطيه الدفعات: نقص معلّق بسعر آخر دفعة للصنف (إن سُمح، كالبيع)، وإلا يُرفض.
 * - تكلفة الحركة = مجموع (الكمية × سعر الدفعة) للتخصيصات + نقصها المعلّق بسعره المؤقت. لا متوسط مرجّح.
 * - العكس: الصادر تعود كمياته لنفس دفعاته؛ الوارد يُلغى فقط إن لم يُمس شيء من دفعته.
 * - التصحيح (تسوية سعر، تسوية نقص): حركة "قيمة فقط" بنوع الحركة المصحَّحة نفسه، فتبقى قيمة كرت الصنف = قيمة
 *   الدفعات، وتقارير الاستهلاك حسب النوع تشمل الفرق.
 */
class StockLayerService
{
    private const S = UnitConverter::SCALE;

    private const W = 12;

    /** نوع مصدر الدفعة الافتراضي حسب نوع حركة الوارد. */
    private const SOURCE_BY_TYPE = [
        'purchase' => 'receipt',
        'opening' => 'opening_balance',
        'transfer_in' => 'transfer_in',
        'adjustment' => 'adjustment_in',
        'production_in' => 'production_in',
    ];

    public function __construct(private StockLedger $ledger) {}

    // ── القراءة ──────────────────────────────────────────────────────────────

    /** قيمة رصيد الصنف في المخزن من الدفعات: Σ (المتبقي × السعر) − Σ (النقص المعلّق × سعره المؤقت). */
    public function value(int $warehouseId, int $itemId): string
    {
        $layers = (string) (DB::table('stock_layers')->where('warehouse_id', $warehouseId)->where('stock_item_id', $itemId)
            ->where('is_open', true)->selectRaw('COALESCE(SUM(qty_remaining * unit_cost), 0) as v')->value('v') ?? '0');
        $short = (string) (DB::table('stock_layer_shortages')->where('warehouse_id', $warehouseId)->where('stock_item_id', $itemId)
            ->where('status', StockLayerShortage::OPEN)->selectRaw('COALESCE(SUM(qty_open * provisional_unit_cost), 0) as v')->value('v') ?? '0');

        return UnitConverter::round(bcsub($layers, $short, self::W));
    }

    /** سعر آخر دفعة للصنف في المخزن، وإلا في أي مخزن، وإلا صفر (تكلفة النقص المعلّق والزيادة في الجرد). */
    public function latestCost(int $warehouseId, int $itemId): string
    {
        $q = fn (?int $w) => DB::table('stock_layers')->where('stock_item_id', $itemId)->when($w, fn ($x) => $x->where('warehouse_id', $w))
            ->where('is_provisional', false)->orderByDesc('received_at')->orderByDesc('id')->value('unit_cost');

        return (string) ($q($warehouseId) ?? $q(null) ?? '0');
    }

    /** الدفعات المتاحة (لم تنفد) بالأقدم أولاً — لشاشة الصرف (بلا قفل). */
    public function openLayers(int $warehouseId, int $itemId): Collection
    {
        return StockLayer::with(['purchaseBill:id,bill_number,bill_date', 'supplier:id,name', 'origin'])
            ->where('warehouse_id', $warehouseId)->where('stock_item_id', $itemId)->where('is_open', true)
            ->orderBy('received_at')->orderBy('id')->get();
    }

    /** تقدير تكلفة صرف كمية بالأقدم أولاً (بلا قفل ولا تسجيل) — للمعاينة فقط. */
    public function estimateIssue(int $warehouseId, int $itemId, string $quantity): string
    {
        $left = $quantity;
        $cost = '0';
        foreach ($this->openLayers($warehouseId, $itemId) as $layer) {
            if (bccomp($left, '0', self::S) <= 0) {
                break;
            }
            $take = $this->min($left, (string) $layer->qty_remaining);
            $cost = bcadd($cost, bcmul($take, (string) $layer->unit_cost, self::W), self::W);
            $left = bcsub($left, $take, self::S);
        }
        if (bccomp($left, '0', self::S) > 0) {
            $cost = bcadd($cost, bcmul($left, $this->latestCost($warehouseId, $itemId), self::W), self::W);
        }

        return UnitConverter::round($cost);
    }

    // ── الصادر ───────────────────────────────────────────────────────────────

    /**
     * خطة الصرف: التوزيع اليدوي أولاً (يُتحقق منه)، والباقي بالأقدم أولاً، ثم النقص المعلّق إن سُمح.
     *
     * @param  array<int, array{layer_id:int, quantity:string}>|null  $manual  بالوحدة الأساسية
     * @return array{parts: array<int, array{layer: StockLayer, quantity: string, unit_cost: string, total: string}>, shortage: ?array{quantity: string, unit_cost: string, total: string}, total: string}
     */
    public function planIssue(int $warehouseId, int $itemId, string $quantity, ?array $manual, bool $allowShortage, string $itemName): array
    {
        $layers = StockLayer::where('warehouse_id', $warehouseId)->where('stock_item_id', $itemId)->where('is_open', true)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $remaining = $layers->map(fn ($l) => (string) $l->qty_remaining)->all();
        $parts = [];
        $left = $quantity;

        if ($manual) {
            $byLayer = [];
            foreach ($manual as $m) {
                $q = UnitConverter::round((string) $m['quantity']);
                if (bccomp($q, '0', self::S) <= 0) {
                    continue;
                }
                $byLayer[(int) $m['layer_id']] = bcadd($byLayer[(int) $m['layer_id']] ?? '0', $q, self::S);
            }
            foreach ($byLayer as $layerId => $q) {
                $layer = $layers->get($layerId);
                if (! $layer) {
                    throw new InventoryRuleException("الدفعة #{$layerId} غير متاحة للصنف \"{$itemName}\" في هذا المخزن (نفدت أو ليست منه).");
                }
                if (bccomp($q, $remaining[$layerId], self::S) > 0) {
                    throw new InventoryRuleException(sprintf('الكمية المأخوذة من الدفعة #%d للصنف "%s" (%s) أكبر من المتبقي فيها (%s).',
                        $layerId, $itemName, self::trim($q), self::trim($remaining[$layerId])));
                }
                $parts[] = $this->part($layer, $q);
                $remaining[$layerId] = bcsub($remaining[$layerId], $q, self::S);
                $left = bcsub($left, $q, self::S);
            }
            if (bccomp($left, '0', self::S) < 0) {
                throw new InventoryRuleException(sprintf('مجموع التوزيع على الدفعات (%s) أكبر من الكمية المطلوبة (%s) للصنف "%s".',
                    self::trim(bcsub($quantity, $left, self::S)), self::trim($quantity), $itemName));
            }
            $available = array_reduce($remaining, fn ($c, $r) => bcadd($c, $r, self::S), '0');
            if (bccomp($left, '0', self::S) > 0 && bccomp($available, '0', self::S) > 0) {
                throw new InventoryRuleException(sprintf('مجموع التوزيع على الدفعات (%s) لا يساوي الكمية المطلوبة (%s) للصنف "%s".',
                    self::trim(bcsub($quantity, $left, self::S)), self::trim($quantity), $itemName));
            }
        }

        // الباقي بالأقدم أولاً (تاريخ الاستلام ثم الترحيل).
        foreach ($layers->sortBy([['received_at', 'asc'], ['id', 'asc']]) as $layer) {
            if (bccomp($left, '0', self::S) <= 0) {
                break;
            }
            $have = $remaining[$layer->id];
            if (bccomp($have, '0', self::S) <= 0) {
                continue;
            }
            $take = $this->min($left, $have);
            $parts[] = $this->part($layer, $take);
            $remaining[$layer->id] = bcsub($have, $take, self::S);
            $left = bcsub($left, $take, self::S);
        }

        $shortage = null;
        if (bccomp($left, '0', self::S) > 0) {
            if (! $allowShortage) {
                $available = $layers->reduce(fn ($c, $l) => bcadd($c, (string) $l->qty_remaining, self::S), '0');
                throw new InventoryRuleException(sprintf('الرصيد غير كافٍ للصنف "%s": المتاح %s والمطلوب %s (بالوحدة الأساسية).',
                    $itemName, self::trim($available), self::trim($quantity)));
            }
            $cost = $this->latestCost($warehouseId, $itemId);
            $shortage = ['quantity' => $left, 'unit_cost' => $cost, 'total' => UnitConverter::round(bcmul($left, $cost, self::W))];
        }

        $total = array_reduce($parts, fn ($c, $p) => bcadd($c, $p['total'], self::S), $shortage['total'] ?? '0');

        return ['parts' => $parts, 'shortage' => $shortage, 'total' => $total];
    }

    /** تسجيل خطة الصرف بعد إنشاء الحركة: تخصيصات تُنقص الدفعات، ونقص معلّق للباقي. */
    public function applyIssue(StockMovement $movement, array $plan, ?int $lineId): void
    {
        foreach ($plan['parts'] as $p) {
            $this->allocate($p['layer']->id, $movement, StockLayerAllocation::KIND_CONSUME, $p['quantity'], $p['unit_cost'], $p['total'], $lineId);
        }
        if ($plan['shortage']) {
            StockLayerShortage::create([
                'warehouse_id' => $movement->warehouse_id, 'stock_item_id' => $movement->stock_item_id,
                'movement_id' => $movement->id, 'movement_type' => $movement->type, 'movement_line_id' => $lineId,
                'quantity' => $plan['shortage']['quantity'], 'qty_open' => $plan['shortage']['quantity'],
                'provisional_unit_cost' => $plan['shortage']['unit_cost'], 'status' => StockLayerShortage::OPEN,
            ]);
        }
    }

    // ── الوارد ───────────────────────────────────────────────────────────────

    /**
     * وارد التحويل: دفعات الوجهة نسخة من تخصيصات الصادر (نفس السعر ونفس تاريخ الاستلام الأصلي ومرجع الدفعة الأصلية)،
     * والجزء الذي خرج نقصاً معلّقاً يصل دفعة مؤقتة بسعره المؤقت تُصحَّح عند تسوية نقص المصدر.
     *
     * @return array{parts: array<int, array<string, mixed>>, total: string}
     */
    public function planMirror(int $outMovementId): array
    {
        $parts = [];
        $allocations = StockLayerAllocation::with('layer')->where('movement_id', $outMovementId)
            ->where('kind', StockLayerAllocation::KIND_CONSUME)->orderBy('id')->get();
        foreach ($allocations as $a) {
            $l = $a->layer;
            $parts[] = [
                'quantity' => (string) $a->quantity, 'unit_cost' => (string) $a->unit_cost, 'total' => (string) $a->total_cost,
                'received_at' => $l->received_at, 'origin_layer_id' => $l->origin_layer_id ?? $l->id, 'source_allocation_id' => $a->id,
                'purchase_bill_id' => $l->purchase_bill_id, 'supplier_id' => $l->supplier_id, 'is_provisional' => (bool) $l->is_provisional,
                'shortage_id' => null,
            ];
        }
        foreach (StockLayerShortage::where('movement_id', $outMovementId)->orderBy('id')->get() as $s) {
            $parts[] = [
                'quantity' => (string) $s->quantity, 'unit_cost' => (string) $s->provisional_unit_cost,
                'total' => UnitConverter::round(bcmul((string) $s->quantity, (string) $s->provisional_unit_cost, self::W)),
                'received_at' => null, 'origin_layer_id' => null, 'source_allocation_id' => null,
                'purchase_bill_id' => null, 'supplier_id' => null, 'is_provisional' => true, 'shortage_id' => $s->id,
            ];
        }

        return ['parts' => $parts, 'total' => array_reduce($parts, fn ($c, $p) => bcadd($c, $p['total'], self::S), '0')];
    }

    /**
     * دفعة/دفعات الوارد ثم تسوية النقص المعلّق في المخزن نفسه.
     *
     * @param  array<string, mixed>|null  $meta  source_type, source_id, source_line_id, purchase_bill_id, supplier_id, received_at
     * @param  array{parts: array<int, array<string, mixed>>}|null  $mirror
     */
    public function receive(StockMovement $movement, ?array $meta, ?array $mirror, ?User $by): void
    {
        if ($mirror !== null) {
            foreach ($mirror['parts'] as $p) {
                StockLayer::create([
                    'warehouse_id' => $movement->warehouse_id, 'stock_item_id' => $movement->stock_item_id,
                    'source_type' => 'transfer_in', 'source_id' => $movement->source_id, 'source_line_id' => $meta['source_line_id'] ?? null,
                    'movement_id' => $movement->id, 'origin_layer_id' => $p['origin_layer_id'], 'source_allocation_id' => $p['source_allocation_id'],
                    'purchase_bill_id' => $p['purchase_bill_id'], 'supplier_id' => $p['supplier_id'],
                    'received_at' => $p['received_at'] ?? $movement->occurred_at,
                    'qty_in' => $p['quantity'], 'qty_remaining' => $p['quantity'], 'unit_cost' => $p['unit_cost'],
                    'is_provisional' => $p['is_provisional'], 'shortage_id' => $p['shortage_id'],
                ]);
            }
        } else {
            StockLayer::create([
                'warehouse_id' => $movement->warehouse_id, 'stock_item_id' => $movement->stock_item_id,
                'source_type' => $meta['source_type'] ?? self::SOURCE_BY_TYPE[$movement->type] ?? $movement->type,
                'source_id' => $meta['source_id'] ?? $movement->source_id, 'source_line_id' => $meta['source_line_id'] ?? null,
                'movement_id' => $movement->id, 'purchase_bill_id' => $meta['purchase_bill_id'] ?? null, 'supplier_id' => $meta['supplier_id'] ?? null,
                'received_at' => $meta['received_at'] ?? $movement->occurred_at,
                'qty_in' => (string) $movement->quantity, 'qty_remaining' => (string) $movement->quantity, 'unit_cost' => (string) $movement->unit_cost,
            ]);
        }
        $this->settle((int) $movement->warehouse_id, (int) $movement->stock_item_id, (int) $movement->id, $by);
    }

    /**
     * تسوية النقص المعلّق من الدفعات المتاحة (الأقدم أولاً للطرفين). التكلفة المؤقتة تُصحَّح بحركة قيمة بنوع حركة
     * النقص نفسها، وتكلفة الطلب في سجل الخصم تُحدَّث، ودفعة التحويل المؤقتة في الوجهة يُصحَّح جزؤها المقابل.
     */
    public function settle(int $warehouseId, int $itemId, int $settlingMovementId, ?User $by): void
    {
        $shortages = StockLayerShortage::where('warehouse_id', $warehouseId)->where('stock_item_id', $itemId)
            ->where('status', StockLayerShortage::OPEN)->orderBy('id')->lockForUpdate()->get();
        if ($shortages->isEmpty()) {
            return;
        }
        $layers = StockLayer::where('warehouse_id', $warehouseId)->where('stock_item_id', $itemId)->where('is_open', true)
            ->orderBy('id')->lockForUpdate()->get()->sortBy([['received_at', 'asc'], ['id', 'asc']])->values();

        foreach ($shortages as $shortage) {
            foreach ($layers as $layer) {
                $open = (string) $shortage->qty_open;
                $have = (string) $layer->qty_remaining;
                if (bccomp($open, '0', self::S) <= 0) {
                    break;
                }
                if (bccomp($have, '0', self::S) <= 0) {
                    continue;
                }
                $take = $this->min($open, $have);
                $cost = (string) $layer->unit_cost;
                $movement = $shortage->movement_id ? StockMovement::find($shortage->movement_id) : null;
                $allocation = $this->allocate($layer->id, $movement ?? StockMovement::findOrFail($settlingMovementId), StockLayerAllocation::KIND_SETTLE,
                    $take, $cost, UnitConverter::round(bcmul($take, $cost, self::W)), $shortage->movement_line_id, $shortage->id,
                    $shortage->movement_type);
                $layer->qty_remaining = bcsub($have, $take, self::S);
                $rest = bcsub($open, $take, self::S);
                $shortage->forceFill([
                    'qty_open' => $rest,
                    'status' => bccomp($rest, '0', self::S) === 0 ? StockLayerShortage::SETTLED : StockLayerShortage::OPEN,
                    'settled_at' => now(),
                ])->save();

                // فرق التكلفة: الكمية × (سعر الدفعة − السعر المؤقت). الصادر قيمته سالبة، فالتصحيح = − الفرق.
                $delta = UnitConverter::round(bcmul($take, bcsub($cost, (string) $shortage->provisional_unit_cost, self::W), self::W));
                if (bccomp($delta, '0', self::S) !== 0) {
                    $fix = $this->ledger->postValueAdjustment(
                        $warehouseId, $itemId, $movement ? $movement->type : 'revaluation', bcmul($delta, '-1', self::S), $movement?->id,
                        $movement?->source_type, $movement?->source_id, 'تسوية نقص معلّق', $by, $this->value($warehouseId, $itemId),
                    );
                    StockLayerRevaluation::create([
                        'layer_id' => $layer->id, 'allocation_id' => $allocation->id, 'movement_id' => $fix->id, 'quantity' => $take,
                        'old_unit_cost' => (string) $shortage->provisional_unit_cost, 'new_unit_cost' => $cost, 'value' => $delta,
                        'reason' => 'shortage_settlement', 'source_type' => 'shortage', 'source_id' => $shortage->id, 'created_at' => now(),
                    ]);
                    $this->touchSalePosting($movement, $delta);
                }
                // دفعة التحويل المؤقتة في الوجهة: الجزء المقابل يأخذ السعر الفعلي.
                if ($movement?->type === 'transfer_out') {
                    foreach (StockLayer::where('shortage_id', $shortage->id)->where('is_provisional', true)->orderBy('id')->get() as $child) {
                        $this->revalueUnits($child, $take, $cost, 'shortage_settlement', 'shortage', $shortage->id, $by, inPlace: false);
                    }
                }
            }
        }
    }

    // ── العكس ────────────────────────────────────────────────────────────────

    /**
     * خطة عكس حركة: الصادر تعود كمياته لنفس الدفعات (والنقص المفتوح يُلغى)، والوارد تُلغى دفعته إن لم تُمس.
     *
     * @return array{restore: Collection, cancel: Collection, void: Collection, total: string}
     */
    public function planReverse(StockMovement $original): array
    {
        if (bccomp((string) $original->quantity, '0', self::S) === 0) {
            throw new InventoryRuleException('حركة تصحيح التكلفة لا تُعكس.');
        }
        $restore = collect();
        $cancel = collect();
        $void = collect();
        $total = '0';

        if (bccomp((string) $original->quantity, '0', self::S) < 0) {
            $restore = StockLayerAllocation::where('movement_id', $original->id)
                ->whereIn('kind', [StockLayerAllocation::KIND_CONSUME, StockLayerAllocation::KIND_SETTLE])
                ->whereNotExists(fn ($q) => $q->from('stock_layer_allocations as r')->whereColumn('r.reverses_allocation_id', 'stock_layer_allocations.id'))
                ->orderBy('id')->get();
            $cancel = StockLayerShortage::where('movement_id', $original->id)->where('status', StockLayerShortage::OPEN)->lockForUpdate()->get();
            foreach ($restore as $a) {
                $total = bcadd($total, (string) $a->total_cost, self::S);
            }
            foreach ($cancel as $s) {
                $total = bcadd($total, UnitConverter::round(bcmul((string) $s->qty_open, (string) $s->provisional_unit_cost, self::W)), self::S);
            }
        } else {
            $void = StockLayer::with('stockItem:id,name')->where('movement_id', $original->id)->where('source_type', '!=', 'split')
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($void as $layer) {
                if (bccomp((string) $layer->qty_remaining, (string) $layer->qty_in, self::S) !== 0) {
                    throw new InventoryRuleException(sprintf('لا يمكن العكس: صُرف %s من دفعة الصنف "%s" (الوارد %s والمتبقي %s). اعكس الصرف أولاً.',
                        self::trim(bcsub((string) $layer->qty_in, (string) $layer->qty_remaining, self::S)), $layer->stockItem?->name ?? '#'.$layer->stock_item_id,
                        self::trim((string) $layer->qty_in), self::trim((string) $layer->qty_remaining)));
                }
                $total = bcsub($total, UnitConverter::round(bcmul((string) $layer->qty_remaining, (string) $layer->unit_cost, self::W)), self::S);
            }
        }

        return ['restore' => $restore, 'cancel' => $cancel, 'void' => $void, 'total' => $total];
    }

    public function applyReverse(StockMovement $reversal, array $plan, ?User $by): void
    {
        foreach ($plan['restore'] as $a) {
            StockLayerAllocation::create([
                'layer_id' => $a->layer_id, 'movement_id' => $reversal->id, 'movement_type' => $a->movement_type,
                'movement_line_id' => $a->movement_line_id, 'kind' => StockLayerAllocation::KIND_RESTORE,
                'quantity' => bcmul((string) $a->quantity, '-1', self::S), 'unit_cost' => (string) $a->unit_cost,
                'total_cost' => bcmul((string) $a->total_cost, '-1', self::S), 'reverses_allocation_id' => $a->id,
                'shortage_id' => $a->shortage_id, 'created_at' => now(),
            ]);
            DB::table('stock_layers')->where('id', $a->layer_id)->update([
                'qty_remaining' => DB::raw('qty_remaining + '.$a->quantity), 'updated_at' => now(),
            ]);
        }
        foreach ($plan['cancel'] as $s) {
            $s->forceFill(['qty_open' => 0, 'status' => StockLayerShortage::CANCELLED])->save();
        }
        foreach ($plan['void'] as $layer) {
            StockLayerAllocation::create([
                'layer_id' => $layer->id, 'movement_id' => $reversal->id, 'movement_type' => $reversal->type,
                'kind' => StockLayerAllocation::KIND_VOID, 'quantity' => (string) $layer->qty_remaining, 'unit_cost' => (string) $layer->unit_cost,
                'total_cost' => UnitConverter::round(bcmul((string) $layer->qty_remaining, (string) $layer->unit_cost, self::W)), 'created_at' => now(),
            ]);
            $layer->forceFill(['qty_remaining' => 0])->save();
        }
        if ($plan['restore']->isNotEmpty()) {
            $this->settle((int) $reversal->warehouse_id, (int) $reversal->stock_item_id, (int) $reversal->id, $by);
        }
    }

    // ── إعادة التقييم ────────────────────────────────────────────────────────

    /**
     * تغيير سعر وحدات من دفعة ($units منها) إلى $newCost:
     *   1) تصحيح الوارد (+ الوحدات × الفرق) بحركة قيمة بنوع حركة الدفعة.
     *   2) الجزء المصروف (الأقدم أولاً): تصحيح كل حركة صرف (− الكمية × الفرق) بنوعها، وإن كان تحويلاً تُصحَّح دفعات
     *      الوجهة المنسوخة منه بالفرق نفسه (تتابعياً).
     *   3) الجزء المتبقي: يأخذ السعر الجديد — في مكانه ($inPlace) أو بتقسيمه إلى دفعة جديدة (للدفعة المؤقتة).
     * صافي التصحيحات = المتبقي × الفرق = تغيّر قيمة الدفعات، فيبقى كرت الصنف مطابقاً.
     *
     * @return string قيمة الفرق على الوحدات كلها (الوحدات × الفرق)
     */
    public function revalueUnits(StockLayer $layer, string $units, string $newCost, string $reason, ?string $sourceType, ?int $sourceId, ?User $by, bool $inPlace): string
    {
        $layer = StockLayer::whereKey($layer->id)->lockForUpdate()->firstOrFail();
        $old = (string) $layer->unit_cost;
        $delta = bcsub($newCost, $old, self::W);
        if (bccomp($delta, '0', self::S) === 0 || bccomp($units, '0', self::S) <= 0) {
            return '0';
        }
        $w = (int) $layer->warehouse_id;
        $i = (int) $layer->stock_item_id;
        $this->ledger->lockPair($w, $i);
        $running = $this->value($w, $i);
        $origin = $layer->movement_id ? StockMovement::find($layer->movement_id) : null;
        $note = $reason === 'cost_adjustment' ? 'تسوية سعر' : 'تسوية تكلفة مؤقتة';

        // 1) الوارد
        $incoming = UnitConverter::round(bcmul($units, $delta, self::W));
        $running = bcadd($running, $incoming, self::S);
        $fixIn = $this->ledger->postValueAdjustment($w, $i, $origin ? $origin->type : 'revaluation', $incoming, $origin?->id,
            $sourceType, $sourceId, $note, $by, $running);
        $this->revaluation($layer, null, $fixIn, $units, $old, $newCost, $incoming, $reason, $sourceType, $sourceId);

        // 2) المصروف
        $left = $units;
        foreach ($this->netConsumed($layer, $reason) as [$allocation, $net]) {
            if (bccomp($left, '0', self::S) <= 0) {
                break;
            }
            $take = $this->min($left, $net);
            $value = UnitConverter::round(bcmul($take, $delta, self::W));
            $running = bcsub($running, $value, self::S);
            $movement = StockMovement::find($allocation->movement_id);
            $fix = $this->ledger->postValueAdjustment($w, $i, $allocation->movement_type, bcmul($value, '-1', self::S), $movement?->id,
                $movement?->source_type ?? $sourceType, $movement?->source_id ?? $sourceId, $note, $by, $running);
            $this->revaluation($layer, $allocation, $fix, $take, $old, $newCost, $value, $reason, $sourceType, $sourceId);
            $this->touchSalePosting($movement, $value);
            if ($allocation->movement_type === 'transfer_out') {
                foreach (StockLayer::where('source_allocation_id', $allocation->id)->orderBy('id')->get() as $child) {
                    $this->revalueUnits($child, $take, bcadd((string) $child->unit_cost, $delta, self::W), $reason, $sourceType, $sourceId, $by,
                        inPlace: ! $child->is_provisional);
                }
            }
            $left = bcsub($left, $take, self::S);
        }

        // 3) المتبقي
        $layer->refresh();
        $rest = $this->min($left, (string) $layer->qty_remaining);
        if (bccomp($rest, '0', self::S) > 0) {
            if ($inPlace && bccomp($rest, (string) $layer->qty_remaining, self::S) === 0) {
                $layer->forceFill(['unit_cost' => UnitConverter::round($newCost)])->save();
            } else {
                $split = StockLayerAllocation::create([
                    'layer_id' => $layer->id, 'movement_id' => $fixIn->id, 'movement_type' => $fixIn->type, 'kind' => StockLayerAllocation::KIND_SPLIT,
                    'quantity' => $rest, 'unit_cost' => $old, 'total_cost' => UnitConverter::round(bcmul($rest, $old, self::W)), 'created_at' => now(),
                ]);
                $layer->forceFill(['qty_remaining' => bcsub((string) $layer->qty_remaining, $rest, self::S)])->save();
                StockLayer::create([
                    'warehouse_id' => $w, 'stock_item_id' => $i, 'source_type' => 'split', 'source_id' => $layer->id,
                    'movement_id' => $fixIn->id, 'origin_layer_id' => $layer->origin_layer_id ?? $layer->id, 'source_allocation_id' => $split->id,
                    'purchase_bill_id' => $layer->purchase_bill_id, 'supplier_id' => $layer->supplier_id, 'received_at' => $layer->received_at,
                    'qty_in' => $rest, 'qty_remaining' => $rest, 'unit_cost' => UnitConverter::round($newCost),
                ]);
            }
        }

        return $incoming;
    }

    /**
     * الكمية المصروفة الصافية من الدفعة لكل تخصيص (بعد ما أُعيد إليها)، بالأقدم أولاً. تسوية السعر لا تشمل المرتجع
     * للمورّد (سُجّل بسعره وقتها)، وتصحيح الدفعة المؤقتة لا يعيد تصحيح ما صُحّح قبل.
     *
     * @return array<int, array{0: StockLayerAllocation, 1: string}>
     */
    public function netConsumed(StockLayer $layer, string $reason): array
    {
        $allocations = StockLayerAllocation::where('layer_id', $layer->id)
            ->whereIn('kind', [StockLayerAllocation::KIND_CONSUME, StockLayerAllocation::KIND_SETTLE])
            ->when($reason === 'cost_adjustment', fn ($q) => $q->where('movement_type', '!=', 'purchase_return'))
            ->orderBy('id')->get();
        $restored = StockLayerAllocation::whereIn('reverses_allocation_id', $allocations->pluck('id'))->pluck('quantity', 'reverses_allocation_id');
        $corrected = $reason === 'shortage_settlement'
            ? StockLayerRevaluation::whereIn('allocation_id', $allocations->pluck('id'))->where('reason', 'shortage_settlement')
                ->groupBy('allocation_id')->selectRaw('allocation_id, SUM(quantity) as q')->pluck('q', 'allocation_id')
            : collect();
        $out = [];
        foreach ($allocations as $a) {
            $net = bcadd((string) $a->quantity, (string) ($restored[$a->id] ?? '0'), self::S);
            $net = bcsub($net, (string) ($corrected[$a->id] ?? '0'), self::S);
            if (bccomp($net, '0', self::S) > 0) {
                $out[] = [$a, $net];
            }
        }

        return $out;
    }

    // ── أدوات ────────────────────────────────────────────────────────────────

    private function allocate(int $layerId, StockMovement $movement, string $kind, string $quantity, string $unitCost, string $total,
        ?int $lineId, ?int $shortageId = null, ?string $movementType = null): StockLayerAllocation
    {
        $affected = DB::table('stock_layers')->where('id', $layerId)->where('qty_remaining', '>=', $quantity)->update([
            'qty_remaining' => DB::raw('qty_remaining - '.$quantity), 'updated_at' => now(),
        ]);
        if ($affected !== 1) {
            throw new InventoryRuleException("الدفعة #{$layerId} لم تعد تكفي الكمية المطلوبة.");
        }

        return StockLayerAllocation::create([
            'layer_id' => $layerId, 'movement_id' => $movement->id, 'movement_type' => $movementType ?? $movement->type,
            'movement_line_id' => $lineId, 'kind' => $kind, 'quantity' => $quantity, 'unit_cost' => $unitCost,
            'total_cost' => $total, 'shortage_id' => $shortageId, 'created_at' => now(),
        ]);
    }

    /** @return array{layer: StockLayer, quantity: string, unit_cost: string, total: string} */
    private function part(StockLayer $layer, string $quantity): array
    {
        $cost = (string) $layer->unit_cost;

        return ['layer' => $layer, 'quantity' => $quantity, 'unit_cost' => $cost, 'total' => UnitConverter::round(bcmul($quantity, $cost, self::W))];
    }

    private function revaluation(StockLayer $layer, ?StockLayerAllocation $allocation, ?StockMovement $movement, string $quantity, string $old,
        string $new, string $value, string $reason, ?string $sourceType, ?int $sourceId): void
    {
        StockLayerRevaluation::create([
            'layer_id' => $layer->id, 'allocation_id' => $allocation?->id, 'movement_id' => $movement?->id, 'quantity' => $quantity,
            'old_unit_cost' => $old, 'new_unit_cost' => UnitConverter::round($new), 'value' => $value, 'reason' => $reason,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'created_at' => now(),
        ]);
    }

    /** تكلفة الطلب في سجل خصم البيع = تكلفة الدفعات الفعلية: يُضاف لها فرق التسوية. */
    private function touchSalePosting(?StockMovement $movement, string $costDelta): void
    {
        if ($movement && $movement->source_type === SaleConsumptionService::SOURCE_TYPE && bccomp($costDelta, '0', self::S) !== 0) {
            StockSalePosting::where('order_id', $movement->source_id)->update(['total_cost' => DB::raw('total_cost + ('.$costDelta.')')]);
        }
    }

    private function min(string $a, string $b): string
    {
        return bccomp($a, $b, self::S) <= 0 ? $a : $b;
    }

    private static function trim(string $n): string
    {
        return str_contains($n, '.') ? rtrim(rtrim($n, '0'), '.') : $n;
    }
}
