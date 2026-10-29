<?php

namespace App\Services\Inventory;

use App\Models\DepartmentBranchSetting;
use App\Models\StockDocument;
use App\Models\StockItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * دورة حياة المستندات المخزنية: مسودة ← مرحّل ← معكوس.
 *
 * الترحيل لا يكتب الأرصدة بنفسه — يبني أسطر حركات ويمرّرها لـ StockLedger (القاعدة 1)، داخل
 * معاملة واحدة مع تحديث حالة المستند، فإن رُفض سطر لا يتغيّر شيء (القاعدة 3). كل حركة تحمل
 * مفتاح منع تكرار من رقم المستند والسطر، فإعادة إرسال الترحيل لا تخصم مرتين (القاعدة 4).
 *
 * الأنواع: استلام (ينشئ دفعات للأصناف التي تتتبع الصلاحية)، صرف/تحويل بالدفعة، إتلاف، تفريك،
 * إنتاج مصنع، أسياخ شاورما، مبيعات معرض. المرحّل يُصحَّح بعكسه كاملاً؛ والعكس يُرفض إن استُهلك
 * ما أنتجه المستند (رصيد الدفعة لا يكفي) — وهذه حماية "لا يُحذف صادر بيع منه / إنتاج صُدِّر".
 */
class StockDocumentService
{
    public function __construct(
        private StockLedger $ledger,
        private BatchService $batches,
        private RecipeService $recipes,
        private ?AccountingDay $accountingDay = null,
    ) {
        $this->accountingDay ??= AccountingDay::fromConfig();
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $by): StockDocument
    {
        $data = $this->normalize($data, null);

        return DB::transaction(function () use ($data, $by) {
            $doc = StockDocument::create([...Arr::except($data, ['lines']), 'created_by' => $by->id]);
            $doc->forceFill(['number' => StockDocument::TYPES[$doc->type]['prefix'].'-'.str_pad((string) $doc->id, 6, '0', STR_PAD_LEFT)])->save();
            $this->syncLines($doc, $data['lines']);

            return $this->fresh($doc);
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(StockDocument $doc, array $data): StockDocument
    {
        return DB::transaction(function () use ($doc, $data) {
            $doc = $this->lockDraft($doc);
            $data = $this->normalize([...$data, 'type' => $doc->type], $doc);
            $doc->update(Arr::except($data, ['lines', 'type']));
            $this->syncLines($doc, $data['lines']);

            return $this->fresh($doc);
        });
    }

    public function delete(StockDocument $doc): void
    {
        DB::transaction(fn () => $this->lockDraft($doc)->delete());
    }

    /** حالة فاتورة الصرف (مسلّمة / غير مسلّمة) والجهة — لا تمس المخزون فتُعدَّل حتى بعد الترحيل. */
    public function updateDelivery(StockDocument $doc, ?string $status, ?string $destination): StockDocument
    {
        if ($doc->type !== StockDocument::TYPE_TRANSFER) {
            throw new InventoryRuleException('حالة الفاتورة لمستندات الصرف فقط.');
        }
        $doc->forceFill(['delivery_status' => $status, 'destination' => $destination])->save();

        return $this->fresh($doc);
    }

    public function post(StockDocument $doc, User $by): StockDocument
    {
        return DB::transaction(function () use ($doc, $by) {
            $doc = StockDocument::whereKey($doc->id)->lockForUpdate()->firstOrFail();
            if ($doc->status === StockDocument::STATUS_POSTED) {
                // إعادة إرسال طلب ترحيل نجح سابقاً: نُرجع المستند كما هو بدل الخطأ.
                return $this->fresh($doc);
            }
            if (! $doc->isDraft()) {
                throw new InventoryRuleException('لا يُرحَّل إلا مستند في حالة مسودة.');
            }
            $doc->load(['lines.stockItem.units']);
            if ($doc->lines->isEmpty()) {
                throw new InventoryRuleException('لا يمكن ترحيل مستند بلا أصناف.');
            }
            $this->assertCanPost($doc);
            $at = $this->occurredAt($doc->document_date->toDateString());

            $total = match ($doc->type) {
                StockDocument::TYPE_RECEIPT => $this->postReceipt($doc, $at, $by),
                StockDocument::TYPE_TRANSFER => $this->postTransfer($doc, $at, $by),
                StockDocument::TYPE_WASTE => $this->postOutgoing($doc, $at, $by, 'waste'),
                StockDocument::TYPE_SHOWROOM_SALE => $this->postOutgoing($doc, $at, $by, 'sale'),
                StockDocument::TYPE_BREAKDOWN => $this->postBreakdown($doc, $at, $by),
                StockDocument::TYPE_PRODUCTION => $this->postProduction($doc, $at, $by),
                StockDocument::TYPE_SKEWER => $this->postSkewers($doc, $at, $by),
            };

            $revenue = null;
            if ($doc->type === StockDocument::TYPE_SHOWROOM_SALE) {
                // الإيراد = Σ الكمية المُدخلة × سعر الوحدة المُدخلة
                $revenue = $doc->lines->reduce(fn ($c, $l) => bcadd($c, bcmul((string) $l->quantity, (string) $l->sale_price, 6), 6), '0');
            }

            $doc->forceFill([
                'status' => StockDocument::STATUS_POSTED,
                'total_cost' => $total,
                'revenue' => $revenue,
                'posted_by' => $by->id,
                'posted_at' => now(),
            ])->save();

            return $this->fresh($doc);
        }, 3);
    }

    /**
     * عكس مستند مرحّل كاملاً: كل حركاته تُعكس بتكلفتها الأصلية، من الأحدث للأقدم
     * (في التحويل يُعكس الوارد للوجهة قبل الصادر من المصدر). سياسة الرصيد السالب تنطبق، ورصيد الدفعة
     * لا يسمح بعكس وارد استُهلك منه (صادر بيع منه في المعرض، أو إنتاج صُدِّر).
     */
    public function reverse(StockDocument $doc, string $reason, User $by): StockDocument
    {
        return DB::transaction(function () use ($doc, $reason, $by) {
            $doc = StockDocument::whereKey($doc->id)->lockForUpdate()->firstOrFail();
            if ($doc->status !== StockDocument::STATUS_POSTED) {
                throw new InventoryRuleException($doc->status === StockDocument::STATUS_REVERSED
                    ? 'هذا المستند معكوس مسبقاً.'
                    : 'لا يُعكس إلا مستند مرحّل.');
            }

            $movements = $doc->movements()->whereNull('reversal_of_id')->orderByDesc('id')->get();
            foreach ($movements as $movement) {
                $this->ledger->reverse($movement, "عكس المستند {$doc->number}: {$reason}", $by);
            }

            $doc->forceFill([
                'status' => StockDocument::STATUS_REVERSED,
                'reversed_by' => $by->id,
                'reversed_at' => now(),
                'reversal_reason' => mb_substr($reason, 0, 500),
            ])->save();

            return $this->fresh($doc);
        }, 3);
    }

    /**
     * نسب التفريك: الفاقد = (الداخل − مجموع الناتج) ÷ الداخل، ونسبة الأصناف المميزة (صدر + فخذ) من الداخل.
     *
     * @return array{input: string, output: string, loss: string, loss_percent: ?string, prime_percent: ?string}|null
     */
    public function breakdownStats(StockDocument $doc): ?array
    {
        if ($doc->type !== StockDocument::TYPE_BREAKDOWN) {
            return null;
        }
        $doc->loadMissing('lines.stockItem.units');
        $in = '0';
        $out = '0';
        $prime = '0';
        foreach ($doc->lines as $l) {
            $qty = $this->baseQuantity($l->stockItem, (int) $l->unit_id, (string) $l->quantity);
            if ($l->direction === 'in') {
                $in = bcadd($in, $qty, 6);
            } else {
                $out = bcadd($out, $qty, 6);
                if ($l->is_prime) {
                    $prime = bcadd($prime, $qty, 6);
                }
            }
        }
        $has = bccomp($in, '0', 6) > 0;

        return [
            'input' => $in,
            'output' => $out,
            'loss' => bcsub($in, $out, 6),
            'loss_percent' => $has ? bcdiv(bcmul(bcsub($in, $out, 6), '100', 8), $in, 2) : null,
            'prime_percent' => $has ? bcdiv(bcmul($prime, '100', 8), $in, 2) : null,
        ];
    }

    // ── الترحيل حسب النوع ─────────────────────────────────────────────────────

    private function postReceipt(StockDocument $doc, CarbonInterface $at, User $by): string
    {
        $lines = $doc->lines->values();
        $movementLines = [];
        foreach ($lines as $l) {
            $batchId = null;
            if ($l->stockItem->tracks_expiry) {
                $factor = $this->factor($l->stockItem, (int) $l->unit_id);
                $batch = $this->batches->create($l->stockItem, 'receipt', [
                    'stock_document_id' => $doc->id,
                    'supplier_id' => $doc->supplier_id,
                    // سعر الوحدة الأساسية = سعر الوحدة المُدخلة ÷ معامل الوحدة
                    'unit_cost' => UnitConverter::fromBase((string) $l->unit_cost, $factor),
                    'quantity' => UnitConverter::toBase((string) $l->quantity, $factor),
                    'cartons' => $l->cartons,
                    'production_date' => $l->production_date,
                    'expiry_date' => $l->expiry_date,
                    'notes' => $l->notes,
                    'created_by' => $by->id,
                ]);
                $l->forceFill(['created_batch_id' => $batch->id])->save();
                $batchId = $batch->id;
            }
            $movementLines[] = new StockMovementLine(
                warehouseId: $doc->warehouse_id,
                stockItemId: $l->stock_item_id,
                type: 'purchase',
                quantity: (string) $l->quantity,
                unitId: $l->unit_id,
                unitCost: (string) $l->unit_cost,
                occurredAt: $at,
                sourceType: StockDocument::SOURCE_TYPE,
                sourceId: $doc->id,
                idempotencyKey: $this->key($doc, $l->id),
                notes: $this->notes($doc, $l->notes),
                batchId: $batchId,
            );
        }
        $movements = $this->ledger->postMany($movementLines, $by);

        $total = '0';
        foreach ($lines as $i => $line) {
            $m = $movements[$i];
            $line->forceFill(['posted_cost' => $m->total_cost])->save();
            // آخر تكلفة شراء للصنف (لكل وحدة أساسية) — يقرؤها تقرير إعادة الطلب.
            DB::table('stock_items')->where('id', $line->stock_item_id)->update(['last_cost' => $m->unit_cost]);
            $total = bcadd($total, (string) $m->total_cost, 6);
        }

        return $total;
    }

    /**
     * الصرف/التحويل: صادر من المصدر بمتوسط تكلفته (ومن الدفعة المختارة)، ثم وارد للوجهة بالتكلفة نفسها
     * وبالدفعة نفسها، فتنتقل القيمة كما هي ويتبع رصيد الدفعة الكمية إلى مخزن القسم.
     */
    private function postTransfer(StockDocument $doc, CarbonInterface $at, User $by): string
    {
        $lines = $doc->lines->values();
        $this->assertBatchChoice($doc, $lines);
        $out = $this->ledger->postMany($lines->map(fn ($l) => new StockMovementLine(
            warehouseId: $doc->warehouse_id,
            stockItemId: $l->stock_item_id,
            type: 'transfer_out',
            quantity: bcmul((string) $l->quantity, '-1', UnitConverter::SCALE),
            unitId: $l->unit_id,
            occurredAt: $at,
            sourceType: StockDocument::SOURCE_TYPE,
            sourceId: $doc->id,
            idempotencyKey: $this->key($doc, $l->id, 'out'),
            notes: $this->notes($doc, $l->notes),
            batchId: $l->batch_id,
        ))->all(), $by);

        // الوارد بالوحدة الأساسية وبتكلفة الوحدة الأساسية كما سُجّل الصادر.
        $this->ledger->postMany($lines->map(fn ($l, $i) => new StockMovementLine(
            warehouseId: $doc->to_warehouse_id,
            stockItemId: $l->stock_item_id,
            type: 'transfer_in',
            quantity: bcmul((string) $out[$i]->quantity, '-1', UnitConverter::SCALE),
            unitCost: (string) $out[$i]->unit_cost,
            occurredAt: $at,
            sourceType: StockDocument::SOURCE_TYPE,
            sourceId: $doc->id,
            idempotencyKey: $this->key($doc, $l->id, 'in'),
            notes: $this->notes($doc, $l->notes),
            batchId: $l->batch_id,
        ))->all(), $by);

        $total = '0';
        foreach ($lines as $i => $l) {
            $value = bcmul((string) $out[$i]->total_cost, '-1', 6);
            $l->forceFill(['posted_cost' => $value])->save();
            $total = bcadd($total, $value, 6);
        }

        return $total;
    }

    /**
     * صادر بسيط من المخزن: إتلاف (waste) أو مبيعات معرض بالوزن (sale). مبيعات المعرض بلا دفعة محددة
     * تُوزَّع على الدفعات الأقرب انتهاءً أولاً، ولا تتجاوز المتوفر أبداً.
     */
    private function postOutgoing(StockDocument $doc, CarbonInterface $at, User $by, string $type): string
    {
        $lines = $doc->lines->values();
        $isSale = $type === 'sale';
        if (! $isSale) {
            $this->assertBatchChoice($doc, $lines);
        }

        $movementLines = [];
        $owner = [];
        foreach ($lines as $i => $l) {
            $parts = [['batch_id' => $l->batch_id, 'quantity' => null]];
            if ($isSale && ! $l->batch_id) {
                $base = $this->baseQuantity($l->stockItem, (int) $l->unit_id, (string) $l->quantity);
                $parts = $this->batches->allocate($doc->warehouse_id, $l->stock_item_id, $base);
            }
            foreach ($parts as $p => $part) {
                $owner[] = $i;
                $split = $part['quantity'] !== null;
                $movementLines[] = new StockMovementLine(
                    warehouseId: $doc->warehouse_id,
                    stockItemId: $l->stock_item_id,
                    type: $type,
                    quantity: $split ? bcmul($part['quantity'], '-1', 6) : bcmul((string) $l->quantity, '-1', UnitConverter::SCALE),
                    unitId: $split ? null : $l->unit_id,
                    occurredAt: $at,
                    sourceType: StockDocument::SOURCE_TYPE,
                    sourceId: $doc->id,
                    idempotencyKey: $this->key($doc, $l->id, $split ? 'p'.$p : ''),
                    notes: $this->notes($doc, $type === 'waste' ? (StockDocument::WASTE_REASONS[$l->waste_reason] ?? null) : $l->notes),
                    batchId: $part['batch_id'],
                );
            }
        }
        // مبيعات المعرض لا تتجاوز المتوفر مهما كانت سياسة الرصيد السالب.
        $movements = $this->ledger->postMany($movementLines, $by, $isSale ? false : null);

        $values = [];
        foreach ($movements as $k => $m) {
            // قيمة الصادر موجبة في المستند (الحركة نفسها سالبة).
            $values[$owner[$k]] = bcadd($values[$owner[$k]] ?? '0', bcmul((string) $m->total_cost, '-1', 6), 6);
        }
        $total = '0';
        foreach ($lines as $i => $l) {
            $l->forceFill(['posted_cost' => $values[$i] ?? '0'])->save();
            $total = bcadd($total, $values[$i] ?? '0', 6);
        }

        return $total;
    }

    /**
     * التفريك: يخرج الصنف الداخل (مثلاً دجاج كامل) ويدخل الناتج (صدر، فخذ، جناح...) كدفعات جديدة.
     * قيمة الداخل توزَّع على الناتج حسب الوزن × نسبة القيمة (افتراضياً 1 لكل صنف = بالوزن)،
     * فالفاقد يرفع تكلفة كيلو الناتج ولا تضيع قيمة:
     *   تكلفة الناتج = قيمة الداخل × (وزنه × نسبته) ÷ Σ (وزن × نسبة)
     */
    private function postBreakdown(StockDocument $doc, CarbonInterface $at, User $by): string
    {
        $input = $doc->lines->firstWhere('direction', 'in');
        $outputs = $doc->lines->where('direction', 'out')->values();
        $this->assertBatchChoice($doc, collect([$input]));

        $out = $this->ledger->post(new StockMovementLine(
            warehouseId: $doc->warehouse_id,
            stockItemId: $input->stock_item_id,
            type: 'production_out',
            quantity: bcmul((string) $input->quantity, '-1', UnitConverter::SCALE),
            unitId: $input->unit_id,
            occurredAt: $at,
            sourceType: StockDocument::SOURCE_TYPE,
            sourceId: $doc->id,
            idempotencyKey: $this->key($doc, $input->id),
            notes: $this->notes($doc, 'تفريك — داخل'),
            batchId: $input->batch_id,
        ), $by);
        $inputValue = bcmul((string) $out->total_cost, '-1', 6);
        $input->forceFill(['posted_cost' => $inputValue])->save();

        $weights = [];
        $sum = '0';
        foreach ($outputs as $i => $l) {
            $base = $this->baseQuantity($l->stockItem, (int) $l->unit_id, (string) $l->quantity);
            $weights[$i] = ['base' => $base, 'w' => bcmul($base, (string) ($l->value_ratio ?? '1'), 8)];
            $sum = bcadd($sum, $weights[$i]['w'], 8);
        }

        $movementLines = [];
        foreach ($outputs as $i => $l) {
            $share = bccomp($sum, '0', 8) > 0 ? bcdiv(bcmul($inputValue, $weights[$i]['w'], 12), $sum, 12) : '0';
            $unitCost = UnitConverter::round(bcdiv($share, $weights[$i]['base'], 12));
            $batch = $this->batches->create($l->stockItem, 'breakdown', [
                'stock_document_id' => $doc->id,
                'unit_cost' => $unitCost,
                'quantity' => $weights[$i]['base'],
                'production_date' => $doc->document_date,
                'expiry_date' => $l->expiry_date,
                'created_by' => $by->id,
            ]);
            $l->forceFill(['created_batch_id' => $batch->id, 'posted_cost' => UnitConverter::round($share)])->save();
            $movementLines[] = new StockMovementLine(
                warehouseId: $doc->warehouse_id,
                stockItemId: $l->stock_item_id,
                type: 'production_in',
                quantity: $weights[$i]['base'],
                unitCost: $unitCost,
                occurredAt: $at,
                sourceType: StockDocument::SOURCE_TYPE,
                sourceId: $doc->id,
                idempotencyKey: $this->key($doc, $l->id),
                notes: $this->notes($doc, 'تفريك — ناتج'),
                batchId: $batch->id,
            );
        }
        $this->ledger->postMany($movementLines, $by);

        return $inputValue;
    }

    /**
     * إنتاج المصنع اليومي: كل سطر منتج بوزنه يدخل مخزن المنتجات كدفعة برقم منتج تلقائي، بتكلفة
     * وصفته الحالية. المواد الخام لا تُخصم هنا — استهلاكها الفعلي من جرد مخزن المواد اليومي، والمفترض
     * منها = الوزن المنتج × الوصفة (لكل 1 كغ) في الإنتاجية اليومية.
     */
    private function postProduction(StockDocument $doc, CarbonInterface $at, User $by): string
    {
        $day = $doc->document_date->toDateString();
        $movementLines = [];
        foreach ($doc->lines->values() as $l) {
            $recipe = $this->recipes->versionAt(null, $l->stock_item_id, $day);
            if (! $recipe) {
                throw new InventoryRuleException("المنتج \"{$l->stockItem->name}\" بلا وصفة سارية — أضفها قبل تسجيل الإنتاج.");
            }
            $base = $this->baseQuantity($l->stockItem, (int) $l->unit_id, (string) $l->quantity);
            // تكلفة الوحدة الأساسية للمنتج = تكلفة مكوّنات وحدة واحدة حسب الوصفة (بمتوسط التكلفة الحالي)
            $unitCost = $this->recipes->unitCost($recipe, $day)['cost'];
            $batch = $this->batches->create($l->stockItem, 'production', [
                'stock_document_id' => $doc->id,
                'unit_cost' => $unitCost,
                'quantity' => $base,
                'production_date' => $day,
                'expiry_date' => $l->expiry_date,
                'created_by' => $by->id,
            ]);
            $l->forceFill(['created_batch_id' => $batch->id])->save();
            $movementLines[] = new StockMovementLine(
                warehouseId: $doc->to_warehouse_id,
                stockItemId: $l->stock_item_id,
                type: 'production_in',
                quantity: $base,
                unitCost: $unitCost,
                occurredAt: $at,
                sourceType: StockDocument::SOURCE_TYPE,
                sourceId: $doc->id,
                idempotencyKey: $this->key($doc, $l->id),
                notes: $this->notes($doc, $l->notes),
                batchId: $batch->id,
            );
        }

        return $this->postInflow($doc, $movementLines, $by);
    }

    /**
     * أسياخ الشاورما: كل سطر سيخ بوزنه، برقم تسلسلي عام فريد لا يتكرر أبداً، يدخل مخزن القسم
     * بمتوسط تكلفة اللحوم فيه. شرط O2: مجموع أوزان أسياخ اليوم ≤ اللحوم المستهلكة في اليوم —
     * يُفحص هنا متى رُحّل جرد اليوم (فالمستهلك معروف)، وإلا يُعرض في تقرير الشاورما.
     */
    private function postSkewers(StockDocument $doc, CarbonInterface $at, User $by): string
    {
        $warehouse = Warehouse::findOrFail($doc->warehouse_id);
        $settings = DepartmentBranchSetting::for($warehouse->department_id, $warehouse->branch_id);
        if (! $settings?->is_shawarma || ! $settings->skewer_item_id || ! $settings->meat_category_id) {
            throw new InventoryRuleException('اضبط إعداد قسم الشاورما لهذا المخزن (تصنيف اللحوم وصنف السيخ) أولاً.');
        }
        foreach ($doc->lines as $l) {
            if ((int) $l->stock_item_id !== (int) $settings->skewer_item_id) {
                throw new InventoryRuleException("الصنف \"{$l->stockItem->name}\" ليس صنف السيخ المضبوط للقسم.");
            }
        }
        $day = $doc->document_date->toDateString();
        $shawarma = app(ShawarmaService::class);
        $weight = $doc->lines->reduce(fn ($c, $l) => bcadd($c, $this->baseQuantity($l->stockItem, (int) $l->unit_id, (string) $l->quantity), 6), '0');
        $meat = $shawarma->meatConsumed($warehouse, $settings, $day);
        if ($meat !== null) {
            $produced = bcadd($shawarma->skewersProduced($warehouse, $settings, $day)['weight'], $weight, 6);
            if (bccomp($produced, $meat, 6) > 0) {
                throw new InventoryRuleException(sprintf(
                    'مجموع أوزان أسياخ اليوم (%s) أكبر من اللحوم المستهلكة (%s) حسب جرد اليوم.',
                    self::trim($produced), self::trim($meat),
                ));
            }
        }
        $meatCost = $shawarma->meatAverageCost($warehouse, $settings);

        $movementLines = [];
        foreach ($doc->lines->values() as $l) {
            $base = $this->baseQuantity($l->stockItem, (int) $l->unit_id, (string) $l->quantity);
            $serial = $this->batches->nextSerial('shawarma_skewer');
            $batch = $this->batches->create($l->stockItem, 'skewer', [
                'stock_document_id' => $doc->id,
                'unit_cost' => $meatCost,
                'quantity' => $base,
                'production_date' => $l->production_date ?? $day,
                'notes' => $l->notes,
                'created_by' => $by->id,
            ], 'SK-'.str_pad((string) $serial, 6, '0', STR_PAD_LEFT));
            $l->forceFill(['created_batch_id' => $batch->id])->save();
            $movementLines[] = new StockMovementLine(
                warehouseId: $doc->warehouse_id,
                stockItemId: $l->stock_item_id,
                type: 'production_in',
                quantity: $base,
                unitCost: $meatCost,
                occurredAt: $at,
                sourceType: StockDocument::SOURCE_TYPE,
                sourceId: $doc->id,
                idempotencyKey: $this->key($doc, $l->id),
                notes: $this->notes($doc, $batch->code),
                batchId: $batch->id,
            );
        }

        return $this->postInflow($doc, $movementLines, $by);
    }

    /** @param  array<StockMovementLine>  $movementLines */
    private function postInflow(StockDocument $doc, array $movementLines, User $by): string
    {
        $movements = $this->ledger->postMany($movementLines, $by);
        $total = '0';
        foreach ($doc->lines->values() as $i => $l) {
            $l->forceFill(['posted_cost' => $movements[$i]->total_cost])->save();
            $total = bcadd($total, (string) $movements[$i]->total_cost, 6);
        }

        return $total;
    }

    // ── التحقق والأدوات ───────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data, ?StockDocument $current): array
    {
        $type = $data['type'];
        $today = $this->accountingDay->of(now());
        $data['document_date'] = ($data['document_date'] ?? null) ?: $today;
        if ($data['document_date'] > $today) {
            throw new InventoryRuleException('تاريخ المستند لا يمكن أن يكون في المستقبل.', ['document_date' => ['اختر اليوم أو يوماً سابقاً.']]);
        }

        if ($type === StockDocument::TYPE_RECEIPT) {
            if (empty($data['supplier_id'])) {
                throw new InventoryRuleException('اختر المورّد لمستند الاستلام.', ['supplier_id' => ['المورّد مطلوب.']]);
            }
            if (! empty($data['supplier_invoice_no'])) {
                $duplicate = StockDocument::where('type', StockDocument::TYPE_RECEIPT)
                    ->where('supplier_id', $data['supplier_id'])
                    ->where('supplier_invoice_no', $data['supplier_invoice_no'])
                    ->where('status', '!=', StockDocument::STATUS_REVERSED)
                    ->when($current, fn ($q) => $q->where('id', '!=', $current->id))
                    ->value('number');
                if ($duplicate) {
                    throw new InventoryRuleException("فاتورة المورّد هذه مسجّلة مسبقاً في المستند {$duplicate}.",
                        ['supplier_invoice_no' => ['رقم فاتورة مكرّر لنفس المورّد.']]);
                }
            }
        } else {
            $data['supplier_id'] = null;
            $data['supplier_invoice_no'] = null;
        }

        if (in_array($type, [StockDocument::TYPE_TRANSFER, StockDocument::TYPE_PRODUCTION], true)) {
            if (empty($data['to_warehouse_id'])) {
                throw new InventoryRuleException($type === StockDocument::TYPE_PRODUCTION ? 'اختر مخزن منتجات المصنع.' : 'اختر المخزن الوجهة.',
                    ['to_warehouse_id' => ['المخزن الوجهة مطلوب.']]);
            }
            if ((int) $data['to_warehouse_id'] === (int) $data['warehouse_id']) {
                throw new InventoryRuleException('لا يمكن التحويل إلى المخزن نفسه.', ['to_warehouse_id' => ['اختر مخزناً آخر.']]);
            }
        } else {
            $data['to_warehouse_id'] = null;
        }
        if ($type === StockDocument::TYPE_TRANSFER) {
            $data['delivery_status'] = ($data['delivery_status'] ?? null) ?: 'undelivered';
        } else {
            $data['delivery_status'] = null;
            $data['destination'] = null;
        }

        $items = StockItem::with('units')->whereIn('id', array_column($data['lines'], 'stock_item_id'))->get()->keyBy('id');
        $batchOwners = DB::table('stock_batches')->whereIn('id', array_filter(array_column($data['lines'], 'batch_id')))->pluck('stock_item_id', 'id');
        foreach ($data['lines'] as $i => &$line) {
            $item = $items->get((int) $line['stock_item_id']);
            $n = $i + 1;
            if (! $item || ! $item->is_stored) {
                throw new InventoryRuleException("السطر {$n}: الصنف لا يخضع للتخزين.");
            }
            if (! $item->is_active) {
                throw new InventoryRuleException("السطر {$n}: الصنف \"{$item->name}\" موقوف.");
            }
            // الوحدة الافتراضية: وحدة الشراء للاستلام، والأساسية لغيره.
            $default = $type === StockDocument::TYPE_RECEIPT ? ($item->purchase_unit_id ?? $item->base_unit_id) : $item->base_unit_id;
            $line['unit_id'] = (int) ($line['unit_id'] ?? 0) ?: (int) $default;
            if ($line['unit_id'] !== (int) $item->base_unit_id && ! $item->units->contains('unit_id', $line['unit_id'])) {
                throw new InventoryRuleException("السطر {$n}: الوحدة المختارة غير معرّفة للصنف \"{$item->name}\".");
            }
            if (! empty($line['batch_id']) && (int) ($batchOwners[$line['batch_id']] ?? 0) !== (int) $item->id) {
                throw new InventoryRuleException("السطر {$n}: الدفعة لا تخص الصنف \"{$item->name}\".");
            }

            if ($type === StockDocument::TYPE_RECEIPT) {
                if (! isset($line['unit_cost']) || $line['unit_cost'] === '') {
                    throw new InventoryRuleException("السطر {$n}: تكلفة الوحدة مطلوبة للصنف \"{$item->name}\".");
                }
                if ($item->tracks_expiry && empty($line['expiry_date'])) {
                    throw new InventoryRuleException("السطر {$n}: الصنف \"{$item->name}\" يتتبع الصلاحية — أدخل تاريخ الانتهاء.");
                }
                $line['batch_id'] = null;
            } else {
                $line['unit_cost'] = null;
            }
            if ($type === StockDocument::TYPE_WASTE) {
                if (! array_key_exists($line['waste_reason'] ?? '', StockDocument::WASTE_REASONS)) {
                    throw new InventoryRuleException("السطر {$n}: اختر سبب الإتلاف.");
                }
            } else {
                $line['waste_reason'] = null;
            }
            if ($type === StockDocument::TYPE_SHOWROOM_SALE
                && (! isset($line['sale_price']) || $line['sale_price'] === '' || $line['sale_price'] === null || $line['sale_price'] < 0)) {
                throw new InventoryRuleException("السطر {$n}: أدخل سعر بيع الوحدة.");
            }
            if ($type === StockDocument::TYPE_BREAKDOWN) {
                if (! in_array($line['direction'] ?? null, ['in', 'out'], true)) {
                    throw new InventoryRuleException("السطر {$n}: حدّد هل الصنف داخل أم ناتج.");
                }
                if (isset($line['value_ratio']) && $line['value_ratio'] !== null && $line['value_ratio'] <= 0) {
                    throw new InventoryRuleException("السطر {$n}: نسبة القيمة يجب أن تكون أكبر من صفر.");
                }
            } else {
                $line['direction'] = null;
            }
            if (in_array($type, [StockDocument::TYPE_PRODUCTION, StockDocument::TYPE_SKEWER], true) && $item->type === StockItem::TYPE_RAW) {
                throw new InventoryRuleException("السطر {$n}: \"{$item->name}\" مادة خام وليس منتجاً.");
            }
        }
        unset($line);

        if ($type === StockDocument::TYPE_BREAKDOWN) {
            $this->assertBreakdownShape($data['lines'], $items);
        }

        return $data;
    }

    /** التفريك: صنف داخل واحد، وناتج واحد على الأقل، وكلها بوحدة أساسية واحدة، والناتج لا يزيد عن الداخل. */
    private function assertBreakdownShape(array $lines, $items): void
    {
        $ins = array_values(array_filter($lines, fn ($l) => $l['direction'] === 'in'));
        $outs = array_values(array_filter($lines, fn ($l) => $l['direction'] === 'out'));
        if (count($ins) !== 1 || $outs === []) {
            throw new InventoryRuleException('التفريك يحتاج صنفاً داخلاً واحداً وصنفاً ناتجاً واحداً على الأقل.');
        }
        $baseUnits = array_unique(array_map(fn ($l) => (int) $items[(int) $l['stock_item_id']]->base_unit_id, $lines));
        if (count($baseUnits) > 1) {
            throw new InventoryRuleException('أصناف التفريك يجب أن تكون بوحدة أساسية واحدة (وزن) حتى تُحسب نسبة الفاقد.');
        }
        $in = $this->baseQuantity($items[(int) $ins[0]['stock_item_id']], (int) $ins[0]['unit_id'], (string) $ins[0]['quantity']);
        $out = '0';
        foreach ($outs as $l) {
            $out = bcadd($out, $this->baseQuantity($items[(int) $l['stock_item_id']], (int) $l['unit_id'], (string) $l['quantity']), 6);
        }
        if (bccomp($out, $in, 6) > 0) {
            throw new InventoryRuleException('مجموع الناتج أكبر من الصنف الداخل.');
        }
    }

    /**
     * الصرف من صنف له دفعات في المخزن المصدر يكون من دفعة محددة؛ بلا دفعة يُسمح فقط بقدر المخزون غير
     * الموزّع على دفعات (رصيد قديم قبل تتبعها).
     */
    private function assertBatchChoice(StockDocument $doc, $lines): void
    {
        foreach ($lines as $l) {
            if ($l->batch_id) {
                continue;
            }
            $hasBatches = DB::table('stock_batch_balances as bb')->join('stock_batches as b', 'b.id', '=', 'bb.batch_id')
                ->where('bb.warehouse_id', $doc->warehouse_id)->where('b.stock_item_id', $l->stock_item_id)->where('bb.quantity', '>', 0)->exists();
            if (! $hasBatches) {
                continue;
            }
            $base = $this->baseQuantity($l->stockItem, (int) $l->unit_id, (string) $l->quantity);
            if (bccomp($base, $this->batches->unbatched($doc->warehouse_id, $l->stock_item_id), 6) > 0) {
                throw new InventoryRuleException("اختر الدفعة للصنف \"{$l->stockItem->name}\" — رصيده في هذا المخزن موزّع على دفعات.");
            }
        }
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function syncLines(StockDocument $doc, array $lines): void
    {
        $doc->lines()->delete();
        foreach (array_values($lines) as $i => $line) {
            $doc->lines()->create([
                'line_no' => $i + 1,
                'stock_item_id' => (int) $line['stock_item_id'],
                'unit_id' => (int) $line['unit_id'],
                'quantity' => (string) $line['quantity'],
                'unit_cost' => $line['unit_cost'] !== null ? (string) $line['unit_cost'] : null,
                'waste_reason' => $line['waste_reason'] ?? null,
                'notes' => $line['notes'] ?? null,
                'batch_id' => ! empty($line['batch_id']) ? (int) $line['batch_id'] : null,
                'direction' => $line['direction'] ?? null,
                'cartons' => $line['cartons'] ?? null,
                'production_date' => $line['production_date'] ?? null,
                'expiry_date' => $line['expiry_date'] ?? null,
                'value_ratio' => $line['value_ratio'] ?? null,
                'is_prime' => (bool) ($line['is_prime'] ?? false),
                'sale_price' => $line['sale_price'] ?? null,
            ]);
        }
    }

    private function lockDraft(StockDocument $doc): StockDocument
    {
        $locked = StockDocument::whereKey($doc->id)->lockForUpdate()->firstOrFail();
        if (! $locked->isDraft()) {
            throw new InventoryRuleException('المستند المرحّل لا يُعدَّل ولا يُحذف. صحّحه بعكسه.');
        }

        return $locked;
    }

    private function assertCanPost(StockDocument $doc): void
    {
        $inactive = Warehouse::whereIn('id', array_filter([$doc->warehouse_id, $doc->to_warehouse_id]))
            ->where('is_active', false)
            ->value('name');
        if ($inactive) {
            throw new InventoryRuleException("المخزن \"{$inactive}\" موقوف.");
        }
        if ($doc->supplier_id && Supplier::whereKey($doc->supplier_id)->value('status') === 'blocked') {
            throw new InventoryRuleException('المورّد محظور.');
        }
    }

    private function factor(StockItem $item, int $unitId): string
    {
        return $unitId === (int) $item->base_unit_id ? '1' : (string) ($item->units->firstWhere('unit_id', $unitId)?->factor ?? '1');
    }

    /** الكمية بالوحدة الأساسية = الكمية المُدخلة × معامل وحدتها */
    private function baseQuantity(StockItem $item, int $unitId, string $quantity): string
    {
        return UnitConverter::toBase($quantity, $this->factor($item, $unitId));
    }

    /**
     * لحظة الحركات: الآن إن كان المستند بتاريخ اليوم المحاسبي الحالي، وإلا آخر ثانية في يومه
     * المحاسبي (فتقع الحركات في اليوم الصحيح).
     */
    private function occurredAt(string $documentDate): CarbonInterface
    {
        if ($documentDate === $this->accountingDay->of(now())) {
            return now();
        }

        return CarbonImmutable::instance($this->accountingDay->bounds($documentDate)[1])->subSecond();
    }

    private function key(StockDocument $doc, int $lineId, string $suffix = ''): string
    {
        return 'doc:'.$doc->id.':line:'.$lineId.($suffix !== '' ? ':'.$suffix : '');
    }

    private function notes(StockDocument $doc, ?string $lineNotes): string
    {
        return trim($doc->number.' '.($lineNotes ?? ''));
    }

    private static function trim(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }

    private function fresh(StockDocument $doc): StockDocument
    {
        return $doc->fresh([
            'lines.stockItem:id,code,name,base_unit_id,tracks_expiry', 'lines.stockItem.baseUnit:id,name', 'lines.unit:id,name',
            'lines.batch:id,code,expiry_date', 'lines.createdBatch:id,code,expiry_date',
            'warehouse:id,name,code', 'toWarehouse:id,name,code', 'supplier:id,name,code',
            'creator:id,name', 'poster:id,name', 'reverser:id,name',
        ]);
    }
}
