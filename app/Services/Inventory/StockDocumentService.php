<?php

namespace App\Services\Inventory;

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
 * الأنواع: استلام، صرفية أصناف (تحويل)، إتلاف. المرحّل يُصحَّح بعكسه كاملاً، وسياسة الرصيد السالب تنطبق على العكس
 * (لا يُعكس استلام صُرف منه ولم يعد الرصيد يكفي). في الصرفية لكل سطر مخزن وجهة (افتراضياً مخزن الرأس).
 */
class StockDocumentService
{
    public function __construct(
        private StockLedger $ledger,
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

    /**
     * تكرار مستند صرف كمسودة جديدة: المخزنان والجهة والملاحظات والأصناف بكمياتها ووحداتها ووجهة كل سطر،
     * بتاريخ اليوم ووقته. الرصيد والتكلفة يُحسبان من جديد عند الترحيل.
     */
    public function duplicate(StockDocument $doc, User $by): StockDocument
    {
        if ($doc->type !== StockDocument::TYPE_TRANSFER) {
            throw new InventoryRuleException('التكرار لمستندات الصرف فقط.');
        }
        $doc->loadMissing('lines');

        return $this->create([
            'type' => $doc->type,
            'warehouse_id' => $doc->warehouse_id,
            'to_warehouse_id' => $doc->to_warehouse_id,
            'destination' => $doc->destination,
            'notes' => $doc->notes,
            'quantities_only' => $doc->quantities_only,
            'document_date' => null,
            'document_time' => null,
            'lines' => $doc->lines->map(fn ($l) => [
                'stock_item_id' => $l->stock_item_id,
                'to_warehouse_id' => $l->to_warehouse_id,
                'unit_id' => $l->unit_id,
                'quantity' => (string) $l->quantity,
                'notes' => $l->notes,
            ])->all(),
        ], $by);
    }

    public function delete(StockDocument $doc): void
    {
        DB::transaction(fn () => $this->lockDraft($doc)->delete());
    }

    /** "الجهة" في مستند الصرف — لا تمس المخزون فتُعدَّل حتى بعد الترحيل. */
    public function updateDestination(StockDocument $doc, ?string $destination): StockDocument
    {
        if ($doc->type !== StockDocument::TYPE_TRANSFER) {
            throw new InventoryRuleException('الجهة لمستندات الصرف فقط.');
        }
        $doc->forceFill(['destination' => $destination])->save();

        return $this->fresh($doc);
    }

    /**
     * تأكيد الاستلام الفعلي في مخازن الوجهة: توثيق فقط (من ومتى)، والأرصدة تحرّكت عند الترحيل.
     * $warehouseIds = الوجهات التي يملك المستخدم تأكيدها (يحسبها المتحكم من صلاحياته). المستند يصبح
     * "مستلَماً" عند اكتمال كل الوجهات. لا يُلغى التأكيد؛ تصحيح المستلَم بعكسه (لمن يملك عكس المستلَم).
     *
     * @param  array<int>  $warehouseIds
     */
    public function confirmReceipt(StockDocument $doc, User $by, array $warehouseIds): StockDocument
    {
        return DB::transaction(function () use ($doc, $by, $warehouseIds) {
            $doc = StockDocument::whereKey($doc->id)->lockForUpdate()->firstOrFail();
            if ($doc->type !== StockDocument::TYPE_TRANSFER) {
                throw new InventoryRuleException('تأكيد الاستلام لمستندات الصرف فقط.');
            }
            if ($doc->status !== StockDocument::STATUS_POSTED) {
                throw new InventoryRuleException('يُؤكَّد استلام مستند مرحّل فقط.');
            }
            if ($doc->isReceived()) {
                throw new InventoryRuleException('استلام هذا المستند مؤكَّد مسبقاً.');
            }
            $doc->load(['lines', 'receipts']);
            $received = $doc->receipts->pluck('warehouse_id')->map(fn ($id) => (int) $id)->all();
            $pending = array_values(array_diff($doc->destinationIds(), $received));
            $targets = array_values(array_intersect($pending, array_map('intval', $warehouseIds)));
            if ($targets === []) {
                throw new InventoryRuleException('لا توجد وجهة بانتظار تأكيدك في هذه الصرفية.');
            }
            $at = now();
            foreach ($targets as $warehouseId) {
                $doc->receipts()->create(['warehouse_id' => $warehouseId, 'received_by' => $by->id, 'received_at' => $at]);
            }
            if (count($targets) === count($pending)) {
                $doc->forceFill(['received_by' => $by->id, 'received_at' => $at, 'delivery_status' => 'delivered'])->save();
            }

            return $this->fresh($doc);
        });
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
                StockDocument::TYPE_WASTE => $this->postWaste($doc, $at, $by),
            };

            $doc->forceFill([
                'status' => StockDocument::STATUS_POSTED,
                'total_cost' => $total,
                'posted_by' => $by->id,
                'posted_at' => now(),
            ])->save();

            return $this->fresh($doc);
        }, 3);
    }

    /**
     * عكس مستند مرحّل كاملاً: كل حركاته تُعكس بتكلفتها الأصلية، من الأحدث للأقدم
     * (في التحويل يُعكس الوارد للوجهة قبل الصادر من المصدر). سياسة الرصيد السالب تنطبق.
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

    // ── الترحيل حسب النوع ─────────────────────────────────────────────────────

    private function postReceipt(StockDocument $doc, CarbonInterface $at, User $by): string
    {
        $lines = $doc->lines->values();
        $movements = $this->ledger->postMany($lines->map(fn ($l) => new StockMovementLine(
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
        ))->all(), $by);

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
     * الصرف/التحويل: صادر من المصدر بمتوسط تكلفته، ثم وارد لمخزن وجهة السطر بالتكلفة نفسها، فتنتقل القيمة كما هي.
     * الصرف لا يتجاوز رصيد المصدر أبداً مهما كانت سياسة الرصيد السالب: لا يُنقل ما ليس موجوداً
     * (الصنف المكرر في عدة أسطر يُحسب تراكمياً على رصيد المصدر).
     */
    private function postTransfer(StockDocument $doc, CarbonInterface $at, User $by): string
    {
        $lines = $doc->lines->values();
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
        ))->all(), $by, false);

        // الوارد بالوحدة الأساسية وبتكلفة الوحدة الأساسية كما سُجّل الصادر.
        $this->ledger->postMany($lines->map(fn ($l, $i) => new StockMovementLine(
            warehouseId: (int) ($l->to_warehouse_id ?: $doc->to_warehouse_id),
            stockItemId: $l->stock_item_id,
            type: 'transfer_in',
            quantity: bcmul((string) $out[$i]->quantity, '-1', UnitConverter::SCALE),
            unitCost: (string) $out[$i]->unit_cost,
            occurredAt: $at,
            sourceType: StockDocument::SOURCE_TYPE,
            sourceId: $doc->id,
            idempotencyKey: $this->key($doc, $l->id, 'in'),
            notes: $this->notes($doc, $l->notes),
        ))->all(), $by);

        $total = '0';
        foreach ($lines as $i => $l) {
            $value = bcmul((string) $out[$i]->total_cost, '-1', 6);
            $l->forceFill(['posted_cost' => $value])->save();
            $total = bcadd($total, $value, 6);
        }

        return $total;
    }

    /** الإتلاف: صادر من المخزن بمتوسط التكلفة الحالي، مع سبب لكل سطر. */
    private function postWaste(StockDocument $doc, CarbonInterface $at, User $by): string
    {
        $lines = $doc->lines->values();
        $movements = $this->ledger->postMany($lines->map(fn ($l) => new StockMovementLine(
            warehouseId: $doc->warehouse_id,
            stockItemId: $l->stock_item_id,
            type: 'waste',
            quantity: bcmul((string) $l->quantity, '-1', UnitConverter::SCALE),
            unitId: $l->unit_id,
            occurredAt: $at,
            sourceType: StockDocument::SOURCE_TYPE,
            sourceId: $doc->id,
            idempotencyKey: $this->key($doc, $l->id),
            notes: $this->notes($doc, StockDocument::WASTE_REASONS[$l->waste_reason] ?? null),
        ))->all(), $by);

        $total = '0';
        foreach ($lines as $i => $l) {
            // قيمة الصادر موجبة في المستند (الحركة نفسها سالبة).
            $value = bcmul((string) $movements[$i]->total_cost, '-1', 6);
            $l->forceFill(['posted_cost' => $value])->save();
            $total = bcadd($total, $value, 6);
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
        // وقت الرأس للعرض والطباعة (افتراضياً الآن بتوقيت المنشأة)؛ لحظة الحركات تبقى من occurredAt().
        $data['document_time'] = ($data['document_time'] ?? null)
            ?: ($current?->document_time ?? now()->setTimezone(config('inventory.business_timezone', config('app.timezone')))->format('H:i:s'));
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

        if ($type === StockDocument::TYPE_TRANSFER) {
            if (empty($data['to_warehouse_id'])) {
                throw new InventoryRuleException('اختر المخزن الوجهة.', ['to_warehouse_id' => ['المخزن الوجهة مطلوب.']]);
            }
            if ((int) $data['to_warehouse_id'] === (int) $data['warehouse_id']) {
                throw new InventoryRuleException('لا يمكن التحويل إلى المخزن نفسه.', ['to_warehouse_id' => ['اختر مخزناً آخر.']]);
            }
            // المسودة غير مستلَمة دائماً؛ الاستلام يُؤكَّد بعد الترحيل من مخزن الوجهة (confirmReceipt).
            $data['delivery_status'] = 'undelivered';
            $data['quantities_only'] = (bool) ($data['quantities_only'] ?? false);
        } else {
            $data['to_warehouse_id'] = null;
            $data['delivery_status'] = null;
            $data['destination'] = null;
            $data['quantities_only'] = false;
        }

        $items = StockItem::with('units')->whereIn('id', array_column($data['lines'], 'stock_item_id'))->get()->keyBy('id');
        foreach ($data['lines'] as $i => &$line) {
            $item = $items->get((int) $line['stock_item_id']);
            $n = $i + 1;
            if (! $item || ! $item->is_stored) {
                throw new InventoryRuleException("السطر {$n}: الصنف لا يخضع للتخزين.");
            }
            if (! $item->is_active) {
                throw new InventoryRuleException("السطر {$n}: الصنف \"{$item->name}\" موقوف.");
            }
            if ($type === StockDocument::TYPE_TRANSFER) {
                // وجهة السطر: ما اختاره المستخدم للسطر وحده، وإلا مخزن الرأس.
                $line['to_warehouse_id'] = (int) (($line['to_warehouse_id'] ?? null) ?: $data['to_warehouse_id']);
                if ($line['to_warehouse_id'] === (int) $data['warehouse_id']) {
                    throw new InventoryRuleException("السطر {$n}: مخزن الوجهة هو مخزن المصدر نفسه.",
                        ["lines.{$i}.to_warehouse_id" => ['اختر مخزناً آخر.']]);
                }
            } else {
                $line['to_warehouse_id'] = null;
            }
            // الوحدة الافتراضية: وحدة الشراء للاستلام، والأساسية لغيره.
            $default = $type === StockDocument::TYPE_RECEIPT ? ($item->purchase_unit_id ?? $item->base_unit_id) : $item->base_unit_id;
            $line['unit_id'] = (int) ($line['unit_id'] ?? 0) ?: (int) $default;
            if ($line['unit_id'] !== (int) $item->base_unit_id && ! $item->units->contains('unit_id', $line['unit_id'])) {
                throw new InventoryRuleException("السطر {$n}: الوحدة المختارة غير معرّفة للصنف \"{$item->name}\".");
            }

            if ($type === StockDocument::TYPE_RECEIPT) {
                if (! isset($line['unit_cost']) || $line['unit_cost'] === '') {
                    throw new InventoryRuleException("السطر {$n}: تكلفة الوحدة مطلوبة للصنف \"{$item->name}\".");
                }
            } else {
                $line['unit_cost'] = null;
            }
            if ($type === StockDocument::TYPE_WASTE) {
                if (! array_key_exists($line['waste_reason'] ?? '', StockDocument::WASTE_REASONS)) {
                    throw new InventoryRuleException("السطر {$n}: اختر سبب الهالك.");
                }
            } else {
                $line['waste_reason'] = null;
            }
        }
        unset($line);

        return $data;
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function syncLines(StockDocument $doc, array $lines): void
    {
        $doc->lines()->delete();
        foreach (array_values($lines) as $i => $line) {
            $doc->lines()->create([
                'line_no' => $i + 1,
                'stock_item_id' => (int) $line['stock_item_id'],
                'to_warehouse_id' => $line['to_warehouse_id'] ?? null,
                'unit_id' => (int) $line['unit_id'],
                'quantity' => (string) $line['quantity'],
                'unit_cost' => $line['unit_cost'] !== null ? (string) $line['unit_cost'] : null,
                'waste_reason' => $line['waste_reason'] ?? null,
                'notes' => $line['notes'] ?? null,
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
        $ids = [$doc->warehouse_id, $doc->to_warehouse_id, ...$doc->lines->pluck('to_warehouse_id')->all()];
        $inactive = Warehouse::whereIn('id', array_unique(array_filter($ids)))
            ->where('is_active', false)
            ->value('name');
        if ($inactive) {
            throw new InventoryRuleException("المخزن \"{$inactive}\" موقوف.");
        }
        if ($doc->supplier_id && Supplier::whereKey($doc->supplier_id)->value('status') === 'blocked') {
            throw new InventoryRuleException('المورّد محظور.');
        }
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

    private function fresh(StockDocument $doc): StockDocument
    {
        return $doc->fresh([
            'lines.stockItem:id,code,name,base_unit_id', 'lines.stockItem.baseUnit:id,name', 'lines.unit:id,name',
            'lines.toWarehouse:id,name,code', 'receipts.receiver:id,name',
            'warehouse:id,name,code', 'toWarehouse:id,name,code', 'supplier:id,name,code',
            'creator:id,name', 'poster:id,name', 'reverser:id,name', 'receiver:id,name',
        ]);
    }
}
