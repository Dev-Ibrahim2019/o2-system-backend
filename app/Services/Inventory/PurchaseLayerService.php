<?php

namespace App\Services\Inventory;

use App\Models\PurchaseBill;
use App\Models\PurchaseBillItem;
use App\Models\StockCostAdjustment;
use App\Models\StockDocument;
use App\Models\StockLayer;
use App\Models\StockLayerAllocation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\SupplierAccountingService;
use App\Services\Accounting\SystemAccountProvisioner;
use App\Services\Accounting\TransactionPostingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * فاتورة المشتريات بعد ترحيلها، على مستوى الدفعات:
 *  - دفعات الفاتورة: لكل سطر مخزني دفعته (الوارد، المصروف، المرتجع، المتبقي، السعر).
 *  - مرتجع المشتريات: يخرج من دفعة محددة (الافتراضي دفعة سطر الفاتورة) بسعرها ولا يتجاوز متبقيها، وقيده عكس قيد
 *    الفاتورة (مدين المورّد، دائن المشتريات وضريبة المدخلات).
 *  - تسوية السعر: الفاتورة المرحّلة لا تُعدَّل؛ تصحيح سعر دفعة يعدّل المتبقي ويسجّل الفرق على ما صُرف منها
 *    (وعلى دفعات التحويل المتفرعة)، وقيدها الفرق مع المورّد.
 */
class PurchaseLayerService
{
    private const S = 6;

    public function __construct(
        private StockDocumentService $documents,
        private StockLedger $ledger,
        private SupplierAccountingService $supplierAccounting,
        private TransactionPostingService $posting,
        private SystemAccountProvisioner $accounts,
        private ?AccountingDay $accountingDay = null,
    ) {
        $this->accountingDay ??= AccountingDay::fromConfig();
    }

    // ── دفعات الفاتورة ───────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function layersOf(PurchaseBill $bill): array
    {
        $layers = StockLayer::with(['stockItem:id,code,name,base_unit_id', 'stockItem.baseUnit:id,name', 'warehouse:id,name'])
            ->where('purchase_bill_id', $bill->id)->where('source_type', 'purchase_invoice')->orderBy('id')->get();
        $items = $bill->items()->get()->keyBy('id');

        return $layers->map(function (StockLayer $l) use ($items) {
            $returned = $this->returnedQuantity($l);
            $voided = (string) StockLayerAllocation::where('layer_id', $l->id)->where('kind', StockLayerAllocation::KIND_VOID)->sum('quantity');
            $consumed = bcsub(bcsub(bcsub((string) $l->qty_in, (string) $l->qty_remaining, self::S), $returned, self::S), $voided, self::S);
            $item = $items->get($l->source_line_id);

            return [
                'layer_id' => $l->id,
                'purchase_bill_item_id' => $l->source_line_id,
                'line_no' => $item?->line_no,
                'stock_item' => ['id' => $l->stockItem?->id, 'code' => $l->stockItem?->code, 'name' => $l->stockItem?->name],
                'base_unit' => $l->stockItem?->baseUnit?->name,
                'line_unit_id' => $item?->unit_id,
                'line_factor' => $item ? $this->factor($item) : '1',
                'warehouse' => $l->warehouse?->name,
                'qty_in' => $this->fmt((string) $l->qty_in),
                'consumed' => $this->fmt($consumed),
                'returned' => $this->fmt($returned),
                'remaining' => $this->fmt((string) $l->qty_remaining),
                'unit_cost' => $this->fmt((string) $l->unit_cost),
                'value' => $this->fmt(bcmul((string) $l->qty_remaining, (string) $l->unit_cost, self::S)),
                'is_voided' => bccomp($voided, '0', self::S) > 0,
            ];
        })->all();
    }

    /** @return Collection<int, StockDocument> مرتجعات الفاتورة */
    public function returnsOf(PurchaseBill $bill): Collection
    {
        return StockDocument::with(['lines.stockItem:id,code,name,base_unit_id', 'lines.unit:id,name', 'creator:id,name'])
            ->where('type', StockDocument::TYPE_PURCHASE_RETURN)->where('purchase_bill_id', $bill->id)->orderBy('id')->get();
    }

    /** @return Collection<int, StockCostAdjustment> */
    public function adjustmentsOf(PurchaseBill $bill): Collection
    {
        return StockCostAdjustment::with(['lines.layer.stockItem:id,code,name', 'creator:id,name'])
            ->where('purchase_bill_id', $bill->id)->orderBy('id')->get();
    }

    // ── مرتجع المشتريات ──────────────────────────────────────────────────────

    /**
     * @param  array<int, array{purchase_bill_item_id:int, quantity:string|float, unit_id?:?int, allocations?:?array}>  $lines
     *         الكمية بوحدة السطر المختارة (افتراضياً وحدة سطر الفاتورة)؛ allocations بالوحدة الأساسية (افتراضياً دفعة السطر نفسه).
     */
    public function createReturn(PurchaseBill $bill, array $lines, ?string $reason, User $by): StockDocument
    {
        return DB::transaction(function () use ($bill, $lines, $reason, $by) {
            $bill = $this->lockPosted($bill);
            $items = $bill->items()->with('stockItem.units')->where('line_kind', 'stock')->get()->keyBy('id');
            $docLines = [];
            foreach ($lines as $n => $l) {
                $item = $items->get((int) $l['purchase_bill_item_id']);
                if (! $item) {
                    throw new InventoryRuleException('السطر '.($n + 1).': ليس سطراً مخزنياً في هذه الفاتورة.');
                }
                $unitId = (int) (($l['unit_id'] ?? null) ?: $item->unit_id);
                $factor = $this->factorFor($item, $unitId);
                $base = UnitConverter::toBase((string) $l['quantity'], $factor);
                $allocations = $l['allocations'] ?? null;
                if (! $allocations) {
                    // الافتراضي: دفعة سطر الفاتورة نفسه.
                    $layer = StockLayer::where('purchase_bill_id', $bill->id)->where('source_type', 'purchase_invoice')
                        ->where('source_line_id', $item->id)->orderBy('id')->first();
                    if (! $layer) {
                        throw new InventoryRuleException("لا توجد دفعة لسطر الصنف \"{$item->stockItem->name}\" في هذه الفاتورة.");
                    }
                    $allocations = [['layer_id' => $layer->id, 'quantity' => $base]];
                }
                $docLines[] = [
                    'stock_item_id' => $item->stock_item_id, 'unit_id' => $unitId, 'quantity' => (string) $l['quantity'],
                    'purchase_bill_item_id' => $item->id, 'allocations' => $allocations, 'notes' => null,
                ];
            }
            if ($docLines === []) {
                throw new InventoryRuleException('اختر صنفاً واحداً على الأقل للمرتجع.');
            }

            $doc = $this->documents->create([
                'type' => StockDocument::TYPE_PURCHASE_RETURN,
                'warehouse_id' => $bill->warehouse_id,
                'supplier_id' => $bill->supplier_id,
                'document_date' => null,
                'notes' => trim("مرتجع للفاتورة {$bill->bill_number} ".($reason ?? '')),
                'lines' => $docLines,
            ], $by);
            $doc->forceFill(['purchase_bill_id' => $bill->id])->save();
            $doc = $this->documents->post($doc, $by);

            // القيد: عكس قيد الفاتورة بقيمة الدفعات المرتجعة (بالشيكل) وضريبتها إن كانت قابلة للخصم.
            $value = '0';
            $vat = '0';
            foreach ($doc->lines as $line) {
                $lineValue = PurchaseMath::round((string) $line->posted_cost, 2);
                $value = bcadd($value, $lineValue, 2);
                if ($bill->tax_recoverable) {
                    $rate = (string) ($items->get($line->purchase_bill_item_id)?->tax_rate ?? '0');
                    $vat = bcadd($vat, PurchaseMath::round(bcdiv(bcmul($lineValue, $rate, 8), '100', 8), 2), 2);
                }
            }
            $txn = $this->runAccounting(fn () => $this->supplierAccounting->recordPurchaseReturn(
                supplier: $bill->supplier,
                credits: array_values(array_filter([
                    ['account_id' => $this->accounts->ensurePurchasesAccount()->id, 'amount' => $value, 'description' => "مرتجع مشتريات — {$doc->number}"],
                    ['account_id' => $this->accounts->ensureInputVatAccount()->id, 'amount' => $vat, 'description' => "ضريبة مدخلات مرتجعة — {$doc->number}"],
                ], fn ($c) => bccomp((string) $c['amount'], '0', 2) > 0)),
                date: $this->accountingDay->of(now()),
                reference: $doc->number,
                branchId: $bill->branch_id,
                description: "مرتجع مشتريات {$doc->number} للفاتورة {$bill->bill_number} — {$bill->supplier->name}",
                sourceType: StockDocument::class,
                sourceId: $doc->id,
            ));
            $doc->forceFill(['transaction_id' => $txn->id])->save();

            return $doc->fresh(['lines.stockItem:id,code,name', 'lines.unit:id,name']);
        }, 3);
    }

    /** عكس مرتجع: تعود الكميات لنفس دفعاتها، ويُعكس قيده. */
    public function reverseReturn(StockDocument $doc, string $reason, User $by): StockDocument
    {
        if ($doc->type !== StockDocument::TYPE_PURCHASE_RETURN) {
            throw new InventoryRuleException('هذا ليس مرتجع مشتريات.');
        }

        return DB::transaction(function () use ($doc, $reason, $by) {
            $doc = $this->documents->reverse($doc, $reason, $by);
            if ($doc->transaction_id && ($txn = Transaction::find($doc->transaction_id))) {
                $this->runAccounting(fn () => $this->posting->reverse($txn, "عكس مرتجع {$doc->number}: {$reason}"));
            }

            return $doc;
        }, 3);
    }

    // ── تسوية السعر ──────────────────────────────────────────────────────────

    /**
     * @param  array<int, array{purchase_bill_item_id:int, unit_cost:string|float}>  $lines  السعر الجديد لوحدة سطر الفاتورة بالشيكل
     *         (بلا الضريبة القابلة للخصم، شاملاً حصته من الخصم والمصاريف).
     */
    public function adjustCost(PurchaseBill $bill, array $lines, string $reason, User $by): StockCostAdjustment
    {
        return DB::transaction(function () use ($bill, $lines, $reason, $by) {
            $bill = $this->lockPosted($bill);
            $items = $bill->items()->with('stockItem.units')->where('line_kind', 'stock')->get()->keyBy('id');
            $adjustment = StockCostAdjustment::create([
                'purchase_bill_id' => $bill->id, 'reason' => mb_substr($reason, 0, 500), 'created_by' => $by->id, 'posted_at' => now(),
            ]);
            $adjustment->forceFill(['number' => 'CAD-'.str_pad((string) $adjustment->id, 6, '0', STR_PAD_LEFT)])->save();

            $total = '0';
            $vat = '0';
            foreach ($lines as $n => $l) {
                $item = $items->get((int) $l['purchase_bill_item_id']);
                if (! $item) {
                    throw new InventoryRuleException('السطر '.($n + 1).': ليس سطراً مخزنياً في هذه الفاتورة.');
                }
                $layer = StockLayer::where('purchase_bill_id', $bill->id)->where('source_type', 'purchase_invoice')
                    ->where('source_line_id', $item->id)->orderBy('id')->lockForUpdate()->first();
                if (! $layer) {
                    throw new InventoryRuleException("لا توجد دفعة لسطر الصنف \"{$item->stockItem->name}\".");
                }
                if (StockLayerAllocation::where('layer_id', $layer->id)->where('kind', StockLayerAllocation::KIND_VOID)->exists()) {
                    throw new InventoryRuleException('دفعة هذا السطر ملغاة (عُكس استلامها).');
                }
                // سعر الوحدة الأساسية = سعر وحدة السطر ÷ معاملها
                $newCost = UnitConverter::fromBase((string) $l['unit_cost'], $this->factor($item));
                $old = (string) $layer->unit_cost;
                if (bccomp($newCost, $old, self::S) === 0) {
                    continue;
                }
                // الفرق يشمل الوارد كله ما عدا ما أُرجع للمورّد (سُجّل بسعره وقتها).
                $basis = bcsub((string) $layer->qty_in, $this->returnedQuantity($layer), self::S);
                $remaining = (string) $layer->qty_remaining;
                $value = $this->ledger->layers()->revalueUnits($layer, $basis, $newCost, 'cost_adjustment',
                    StockCostAdjustment::SOURCE_TYPE, $adjustment->id, $by, inPlace: true);
                $adjustment->lines()->create([
                    'layer_id' => $layer->id, 'old_unit_cost' => $old, 'new_unit_cost' => $newCost,
                    'quantity' => $basis, 'remaining_quantity' => $remaining, 'value' => $value,
                ]);
                $total = bcadd($total, $value, self::S);
                if ($bill->tax_recoverable) {
                    $vat = bcadd($vat, bcdiv(bcmul($value, (string) $item->tax_rate, 10), '100', 10), self::S);
                }
            }
            if ($adjustment->lines()->count() === 0) {
                throw new InventoryRuleException('لم يتغيّر أي سعر.');
            }

            // القيد: الفرق مع المورّد (زيادة = إشعار مدين، نقص = إشعار دائن).
            $amount = PurchaseMath::round(ltrim($total, '-'), 2);
            $tax = PurchaseMath::round(ltrim($vat, '-'), 2);
            $entries = array_values(array_filter([
                ['account_id' => $this->accounts->ensurePurchasesAccount()->id, 'amount' => $amount, 'description' => "تسوية سعر — {$adjustment->number}"],
                ['account_id' => $this->accounts->ensureInputVatAccount()->id, 'amount' => $tax, 'description' => "ضريبة تسوية سعر — {$adjustment->number}"],
            ], fn ($e) => bccomp((string) $e['amount'], '0', 2) > 0));
            $txn = null;
            if ($entries !== []) {
                $common = ['supplier' => $bill->supplier, 'date' => $this->accountingDay->of(now()), 'reference' => $adjustment->number,
                    'branchId' => $bill->branch_id, 'description' => "تسوية سعر {$adjustment->number} للفاتورة {$bill->bill_number}",
                    'sourceType' => StockCostAdjustment::class, 'sourceId' => $adjustment->id];
                $txn = $this->runAccounting(fn () => bccomp($total, '0', self::S) > 0
                    ? $this->supplierAccounting->recordPurchaseInvoice(...[...$common, 'debits' => $entries])
                    : $this->supplierAccounting->recordPurchaseReturn(...[...$common, 'credits' => $entries]));
            }
            $adjustment->forceFill(['total_value' => $total, 'transaction_id' => $txn?->id])->save();

            return $adjustment->fresh(['lines']);
        }, 3);
    }

    // ── أدوات ────────────────────────────────────────────────────────────────

    /** الكمية المرتجعة للمورّد من الدفعة (صافي بعد عكس المرتجعات). */
    private function returnedQuantity(StockLayer $layer): string
    {
        return (string) StockLayerAllocation::where('layer_id', $layer->id)->where('movement_type', 'purchase_return')
            ->whereIn('kind', [StockLayerAllocation::KIND_CONSUME, StockLayerAllocation::KIND_RESTORE])->sum('quantity');
    }

    private function lockPosted(PurchaseBill $bill): PurchaseBill
    {
        $bill = PurchaseBill::whereKey($bill->id)->lockForUpdate()->firstOrFail();
        if (! $bill->is_inventory || ! $bill->isPosted() || ! $bill->stock_document_id) {
            throw new InventoryRuleException('هذا يخص فاتورة مشتريات مرحّلة باستلام مخزني فقط.');
        }
        $bill->load('supplier');

        return $bill;
    }

    private function factor(PurchaseBillItem $item): string
    {
        return $this->factorFor($item, (int) $item->unit_id);
    }

    private function factorFor(PurchaseBillItem $item, int $unitId): string
    {
        $stock = $item->stockItem()->with('units')->first();
        if (! $stock || $unitId === (int) $stock->base_unit_id) {
            return '1';
        }
        $unit = $stock->units->firstWhere('unit_id', $unitId);
        if (! $unit) {
            throw new InventoryRuleException("الوحدة المختارة غير معرّفة للصنف \"{$stock->name}\".");
        }

        return (string) $unit->factor;
    }

    private function runAccounting(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (InventoryRuleException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new InventoryRuleException('تعذّر تسجيل القيد: '.$e->getMessage());
        }
    }

    private function fmt(string $v): string
    {
        $v = bcadd($v, '0', self::S);
        if (str_contains($v, '.')) {
            $v = rtrim(rtrim($v, '0'), '.');
        }

        return $v === '-0' ? '0' : $v;
    }
}
