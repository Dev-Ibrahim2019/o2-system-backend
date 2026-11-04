<?php

namespace App\Services\Inventory;

use App\Models\Account;
use App\Models\PurchaseBill;
use App\Models\StockDocument;
use App\Models\StockItem;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Warehouse;
use App\Services\Accounting\SupplierAccountingService;
use App\Services\Accounting\SystemAccountProvisioner;
use App\Services\Accounting\TransactionPostingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * فاتورة المشتريات الموحّدة = استلام مخزني + ذمة مورّد + سند صرف للمدفوع + قيد يومية، في معاملة واحدة.
 *
 * لا تنسخ منطقاً: الاستلام يُنشأ ويُرحَّل بـ StockDocumentService (الدفعات، متوسط التكلفة، آخر سعر)،
 * والذمة والدفع بـ SupplierAccountingService، والعكس بـ StockDocumentService::reverse و
 * TransactionPostingService::reverse. الحساب نفسه في PurchaseMath.
 *
 * القيد: مدين مشتريات المواد (5310) + حسابات المصاريف + ضريبة المدخلات (1150) / دائن ذمم الموردين (2110).
 * الدفع: مدين ذمم الموردين / دائن الصندوق أو البنك — ويُسجَّل سنداً في جدول السندات مربوطاً بالفاتورة.
 */
class PurchaseInvoiceService
{
    public function __construct(
        private StockDocumentService $documents,
        private SupplierAccountingService $supplierAccounting,
        private TransactionPostingService $posting,
        private SystemAccountProvisioner $accounts,
        private ?AccountingDay $accountingDay = null,
    ) {
        $this->accountingDay ??= AccountingDay::fromConfig();
    }

    // ── المسودة ───────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $data */
    public function save(?PurchaseBill $bill, array $data, User $by): PurchaseBill
    {
        return DB::transaction(function () use ($bill, $data, $by) {
            if ($bill) {
                $bill = $this->lockDraft($bill);
            }
            [$header, $lines] = $this->normalize($data);
            $math = PurchaseMath::compute($lines, $header['discount'], $header['tax_recoverable'], $header['exchange_rate']);

            $attrs = [
                ...$header,
                'subtotal' => $math['totals']['net_before_tax'],
                'tax_total' => $math['totals']['tax'],
                'total' => $math['totals']['total'],
            ];
            if ($bill) {
                $bill->update($attrs);
            } else {
                $bill = PurchaseBill::create([
                    ...$attrs,
                    'is_inventory' => true,
                    'bill_number' => PurchaseBill::generateBillNumber('PI'),
                    'status' => 'draft',
                    'paid_amount' => 0,
                    'created_by' => $by->id,
                ]);
            }

            $bill->items()->delete();
            foreach ($lines as $i => $l) {
                $m = $math['lines'][$i];
                $bill->items()->create([
                    'line_no' => $i + 1,
                    'line_kind' => $l['kind'],
                    'stock_item_id' => $l['stock_item_id'],
                    'unit_id' => $l['unit_id'],
                    'description' => $l['description'],
                    'quantity' => $l['quantity'],
                    'unit_price' => $m['unit_price'],
                    'discount_pct' => $l['discount_pct'],
                    'discount' => $m['line_discount'],
                    'allocated_discount' => $m['allocated_discount'],
                    'tax_rate' => $l['tax_rate'],
                    'tax_amount' => $m['tax'],
                    'total_before_tax' => $m['taxable'],
                    'line_total' => $m['total'],
                    'expiry_date' => $l['expiry_date'],
                    'account_id' => $l['account_id'],
                    'stock_unit_cost' => $l['kind'] === 'stock' ? $m['unit_cost_ils'] : null,
                ]);
            }

            return $this->fresh($bill);
        });
    }

    public function delete(PurchaseBill $bill): void
    {
        DB::transaction(function () use ($bill) {
            $bill = $this->lockDraft($bill);
            $bill->items()->delete();
            $bill->forceDelete();
        });
    }

    /** تكرار: مسودة جديدة بنفس المورّد والمخزن والأسطر، بتاريخ اليوم وبلا صلاحيات ولا رقم فاتورة مورّد. */
    public function duplicate(PurchaseBill $bill, User $by): PurchaseBill
    {
        if (! $bill->is_inventory) {
            throw new InventoryRuleException('الفواتير القديمة لا تُكرَّر — أنشئ فاتورة مشتريات جديدة.');
        }
        $bill->loadMissing('items');

        return $this->save(null, [
            'supplier_id' => $bill->supplier_id,
            'warehouse_id' => $bill->warehouse_id,
            'currency' => $bill->currency,
            'exchange_rate' => (string) $bill->exchange_rate,
            'payment_mode' => $bill->payment_mode,
            'tax_recoverable' => $bill->tax_recoverable,
            'discount' => (string) $bill->discount,
            'paid_on_post' => (string) $bill->paid_on_post,
            'cash_account_id' => $bill->cash_account_id,
            'notes' => $bill->notes,
            'lines' => $bill->items->sortBy('line_no')->map(fn ($l) => [
                'kind' => $l->line_kind,
                'stock_item_id' => $l->stock_item_id,
                'unit_id' => $l->unit_id,
                'description' => $l->description,
                'quantity' => (string) $l->quantity,
                'unit_price' => (string) $l->unit_price,
                'discount_pct' => (string) $l->discount_pct,
                'tax_rate' => (string) $l->tax_rate,
                'account_id' => $l->account_id,
            ])->values()->all(),
        ], $by);
    }

    // ── الترحيل ───────────────────────────────────────────────────────────────

    /**
     * الترحيل الكامل أو لا شيء: استلام المخزن، قيد الذمة، والدفعة الفورية إن وُجدت.
     * إعادة إرسال ترحيل نجح سابقاً تُرجع الفاتورة كما هي.
     */
    public function post(PurchaseBill $bill, User $by): PurchaseBill
    {
        return DB::transaction(function () use ($bill, $by) {
            $bill = PurchaseBill::whereKey($bill->id)->lockForUpdate()->firstOrFail();
            if ($bill->isPosted()) {
                return $this->fresh($bill);
            }
            if ($bill->status !== 'draft' || ! $bill->is_inventory) {
                throw new InventoryRuleException('لا يُرحَّل إلا مسودة فاتورة مشتريات.');
            }
            $bill->load(['items.stockItem.units', 'supplier', 'warehouse']);
            $this->assertCanPost($bill);

            $lines = $bill->items->sortBy('line_no')->values();
            $math = PurchaseMath::compute($this->mathLines($lines), (string) $bill->discount, (bool) $bill->tax_recoverable, (string) $bill->exchange_rate);
            $total = $math['totals']['total'];
            $paid = match ($bill->payment_mode) {
                'cash' => $total,
                'partial' => PurchaseMath::round((string) $bill->paid_on_post, 2),
                default => '0',
            };
            if (bccomp($paid, $total, 2) > 0) {
                throw new InventoryRuleException('المدفوع أكبر من إجمالي الفاتورة.', ['paid_on_post' => ['المدفوع ≤ الإجمالي.']]);
            }
            if (bccomp($paid, '0', 2) > 0 && ! $bill->cash_account_id) {
                throw new InventoryRuleException('اختر الصندوق أو الحساب البنكي للمبلغ المدفوع.', ['cash_account_id' => ['مطلوب عند الدفع.']]);
            }
            $date = $bill->bill_date->toDateString();

            // 1) الاستلام المخزني — بخدمة الاستلام الحالية نفسها
            $stockLines = $lines->filter(fn ($l) => $l->line_kind === 'stock')->values();
            $grn = null;
            if ($stockLines->isNotEmpty()) {
                $grn = $this->documents->create([
                    'type' => StockDocument::TYPE_RECEIPT,
                    'warehouse_id' => $bill->warehouse_id,
                    'supplier_id' => $bill->supplier_id,
                    // رقم فاتورة المورّد على الفاتورة الأم (تكراره تحذير لا منع)؛ الاستلام مربوط بـ purchase_bill_id
                    'supplier_invoice_no' => null,
                    'document_date' => $date,
                    'notes' => "فاتورة مشتريات {$bill->bill_number}",
                    'lines' => $stockLines->map(fn ($l) => [
                        'stock_item_id' => $l->stock_item_id,
                        'unit_id' => $l->unit_id,
                        'quantity' => (string) $l->quantity,
                        'unit_cost' => (string) $l->stock_unit_cost,
                        'expiry_date' => $l->expiry_date?->toDateString(),
                        'notes' => null,
                    ])->all(),
                ], $by);
                $grn->forceFill(['purchase_bill_id' => $bill->id])->save();
                $grn = $this->documents->post($grn, $by);
            }

            // 2) قيد الفاتورة وذمة المورّد
            $debits = [];
            if (bccomp($math['gl']['purchases'], '0', 2) > 0) {
                $debits[] = ['account_id' => $this->accounts->ensurePurchasesAccount()->id, 'amount' => $math['gl']['purchases'],
                    'description' => "مشتريات مواد — {$bill->bill_number}"];
            }
            foreach ($math['gl']['expenses'] as $accountId => $amount) {
                $debits[] = ['account_id' => $accountId, 'amount' => $amount, 'description' => "مصاريف مشتريات — {$bill->bill_number}"];
            }
            if (bccomp($math['gl']['input_vat'], '0', 2) > 0) {
                $debits[] = ['account_id' => $this->accounts->ensureInputVatAccount()->id, 'amount' => $math['gl']['input_vat'],
                    'description' => "ضريبة مدخلات — {$bill->bill_number}"];
            }
            if ($debits === []) {
                throw new InventoryRuleException('إجمالي الفاتورة صفر — لا شيء يُرحَّل.');
            }
            $txn = $this->runAccounting(fn () => $this->supplierAccounting->recordPurchaseInvoice(
                supplier: $bill->supplier,
                debits: $debits,
                date: $date,
                reference: $bill->bill_number,
                branchId: $bill->branch_id,
                description: "فاتورة مشتريات {$bill->bill_number} — {$bill->supplier->name}",
                sourceType: PurchaseBill::class,
                sourceId: $bill->id,
            ));

            $bill->forceFill([
                'status' => 'unpaid',
                'subtotal' => $math['totals']['net_before_tax'],
                'tax_total' => $math['totals']['tax'],
                'total' => $total,
                'paid_amount' => 0,
                'paid_on_post' => $paid,
                'journal_entry_id' => $txn->id,
                'stock_document_id' => $grn?->id,
                'due_date' => $bill->payment_mode === 'cash' ? $date : $bill->due_date,
                'posted_by' => $by->id,
                'posted_at' => now(),
            ])->save();

            // 3) سند الصرف للمدفوع الآن
            if (bccomp($paid, '0', 2) > 0) {
                $this->recordPayment($bill, $paid, (int) $bill->cash_account_id, $date, $by);
            }

            return $this->fresh($bill);
        }, 3);
    }

    /** تسديد (جزء من) المتبقي لاحقاً: قيد الدفع + سند صرف مربوط بالفاتورة. */
    public function pay(PurchaseBill $bill, string $amount, int $cashAccountId, ?string $date, User $by): PurchaseBill
    {
        return DB::transaction(function () use ($bill, $amount, $cashAccountId, $date, $by) {
            $bill = PurchaseBill::whereKey($bill->id)->lockForUpdate()->firstOrFail();
            if (! $bill->is_inventory || ! $bill->isPosted()) {
                throw new InventoryRuleException('يُسدَّد فقط لفاتورة مشتريات مرحّلة.');
            }
            $amount = PurchaseMath::round($amount, 2);
            $remaining = bcsub((string) $bill->total, (string) $bill->paid_amount, 2);
            if (bccomp($amount, '0', 2) <= 0 || bccomp($amount, $remaining, 2) > 0) {
                throw new InventoryRuleException("مبلغ التسديد بين 0.01 والمتبقي ({$remaining}).", ['amount' => ['المبلغ ≤ المتبقي.']]);
            }
            $this->recordPayment($bill, $amount, $cashAccountId, $date ?: $this->accountingDay->of(now()), $by);

            return $this->fresh($bill);
        });
    }

    // ── العكس ─────────────────────────────────────────────────────────────────

    /**
     * يعكس الأربعة معاً: الاستلام (بخدمته)، قيد الفاتورة، وكل الدفعات وسنداتها. يُفحص الرصيد قبلها، فإن
     * صُرف شيء من الأصناف يُرفض العكس برسالة تذكر كل صنف وكم ينقص.
     */
    public function reverse(PurchaseBill $bill, string $reason, User $by): PurchaseBill
    {
        return DB::transaction(function () use ($bill, $reason, $by) {
            $bill = PurchaseBill::whereKey($bill->id)->lockForUpdate()->firstOrFail();
            if ($bill->status === 'reversed') {
                throw new InventoryRuleException('هذه الفاتورة معكوسة مسبقاً.');
            }
            if (! $bill->is_inventory || ! $bill->isPosted()) {
                throw new InventoryRuleException('لا يُعكس إلا فاتورة مشتريات مرحّلة.');
            }
            $note = "عكس فاتورة المشتريات {$bill->bill_number}: {$reason}";

            if ($bill->stock_document_id) {
                $grn = StockDocument::with('lines.stockItem.units', 'lines.stockItem.baseUnit')->findOrFail($bill->stock_document_id);
                $this->assertStockSufficient($grn);
                $this->documents->reverse($grn, $note, $by);
            }

            foreach ($bill->vouchers()->where('status', 'active')->get() as $voucher) {
                if ($voucher->transaction_id) {
                    $this->runAccounting(fn () => $this->posting->reverse(Transaction::findOrFail($voucher->transaction_id), $note));
                }
                $voucher->update(['status' => 'cancelled']);
            }
            if ($bill->journal_entry_id) {
                $this->runAccounting(fn () => $this->posting->reverse(Transaction::findOrFail($bill->journal_entry_id), $note));
            }

            $bill->forceFill([
                'status' => 'reversed',
                'reversed_by' => $by->id,
                'reversed_at' => now(),
                'reversal_reason' => mb_substr($reason, 0, 500),
            ])->save();

            return $this->fresh($bill);
        }, 3);
    }

    // ── للواجهة ───────────────────────────────────────────────────────────────

    /** رصيد المورّد الحالي (الدفتر العام) وتاريخ آخر فاتورة مرحّلة وشروط الدفع. */
    public function supplierSummary(Supplier $supplier): array
    {
        $last = PurchaseBill::where('supplier_id', $supplier->id)->whereIn('status', PurchaseBill::POSTED_STATUSES)
            ->orderByDesc('bill_date')->value('bill_date');

        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'balance' => round($this->supplierAccounting->getBalance($supplier), 2),
            'last_invoice_date' => $last ? CarbonImmutable::parse($last)->toDateString() : null,
            'payment_terms' => $supplier->payment_terms,
            'credit_days' => self::creditDays($supplier->payment_terms),
            'tax_registered' => filled($supplier->tax_number),
            'currency' => $supplier->currency ?: 'ILS',
        ];
    }

    /**
     * آخر سعر شراء من المورّد نفسه لكل صنف، بالشيكل للوحدة الأساسية:
     * آخر فاتورة مشتريات مرحّلة، وإلا آخر استلام مرحّل، وإلا سعر ربط الصنف بالمورّد.
     *
     * @param  array<int>  $itemIds
     * @return array<int, array{price_base: string, source: string, date: ?string}>
     */
    public function lastPrices(int $supplierId, array $itemIds): array
    {
        $out = [];
        $items = StockItem::with('units')->whereIn('id', $itemIds)->get()->keyBy('id');
        $factor = fn (StockItem $item, $unitId) => $unitId === null || (int) $unitId === (int) $item->base_unit_id
            ? '1' : (string) ($item->units->firstWhere('unit_id', (int) $unitId)?->factor ?? '1');

        foreach ($items as $id => $item) {
            $pi = DB::table('purchase_bill_items as l')->join('purchase_bills as b', 'b.id', '=', 'l.purchase_bill_id')
                ->where('b.supplier_id', $supplierId)->where('l.stock_item_id', $id)->whereIn('b.status', PurchaseBill::POSTED_STATUSES)
                ->whereNull('b.deleted_at')->orderByDesc('b.bill_date')->orderByDesc('b.id')
                ->first(['l.unit_price', 'l.unit_id', 'b.exchange_rate', 'b.bill_date']);
            if ($pi) {
                $out[$id] = ['price_base' => bcdiv(bcmul((string) $pi->unit_price, (string) $pi->exchange_rate, 10), $factor($item, $pi->unit_id), 6),
                    'source' => 'invoice', 'date' => (string) $pi->bill_date];

                continue;
            }
            $grn = DB::table('stock_document_lines as l')->join('stock_documents as d', 'd.id', '=', 'l.stock_document_id')
                ->where('d.type', 'receipt')->where('d.status', 'posted')->where('d.supplier_id', $supplierId)->where('l.stock_item_id', $id)
                ->orderByDesc('d.document_date')->orderByDesc('d.id')->first(['l.unit_cost', 'l.unit_id', 'd.document_date']);
            if ($grn) {
                $out[$id] = ['price_base' => bcdiv((string) $grn->unit_cost, $factor($item, $grn->unit_id), 6), 'source' => 'receipt', 'date' => (string) $grn->document_date];

                continue;
            }
            $link = DB::table('supplier_stock_item')->where('supplier_id', $supplierId)->where('stock_item_id', $id)->whereNotNull('price')->first(['price', 'unit_id']);
            if ($link) {
                $out[$id] = ['price_base' => bcdiv((string) $link->price, $factor($item, $link->unit_id), 6), 'source' => 'supplier', 'date' => null];
            }
        }

        return $out;
    }

    /** "net30" → 30، "cash"/"cod" → 0، وغير المعروف → 30. */
    public static function creditDays(?string $terms): int
    {
        if (! $terms) {
            return 30;
        }
        if (preg_match('/(\d+)/', $terms, $m)) {
            return (int) $m[1];
        }

        return in_array(strtolower($terms), ['cash', 'cod', 'immediate'], true) ? 0 : 30;
    }

    // ── داخلي ─────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}
     */
    private function normalize(array $data): array
    {
        $supplier = Supplier::findOrFail($data['supplier_id']);
        $today = $this->accountingDay->of(now());
        $billDate = ($data['bill_date'] ?? null) ?: $today;
        if ($billDate > $today) {
            throw new InventoryRuleException('تاريخ الفاتورة لا يمكن أن يكون في المستقبل.', ['bill_date' => ['اختر اليوم أو يوماً سابقاً.']]);
        }
        $mode = ($data['payment_mode'] ?? null) ?: 'credit';
        $currency = strtoupper(($data['currency'] ?? null) ?: ($supplier->currency ?: 'ILS'));
        $rate = $currency === 'ILS' ? '1' : (string) (($data['exchange_rate'] ?? null) ?: '1');
        $dueDate = $mode === 'cash' ? $billDate
            : (($data['due_date'] ?? null) ?: CarbonImmutable::parse($billDate)->addDays(self::creditDays($supplier->payment_terms))->toDateString());
        if ($dueDate < $billDate) {
            throw new InventoryRuleException('تاريخ الاستحقاق قبل تاريخ الفاتورة.', ['due_date' => ['اختر تاريخاً بعد تاريخ الفاتورة.']]);
        }

        $header = [
            'supplier_id' => $supplier->id,
            'warehouse_id' => (int) $data['warehouse_id'],
            'branch_id' => Warehouse::whereKey($data['warehouse_id'])->value('branch_id'),
            'supplier_invoice_no' => ($data['supplier_invoice_no'] ?? null) ?: null,
            'bill_date' => $billDate,
            'due_date' => $dueDate,
            'payment_mode' => $mode,
            'currency' => $currency,
            'exchange_rate' => $rate,
            'tax_recoverable' => array_key_exists('tax_recoverable', $data) && $data['tax_recoverable'] !== null
                ? (bool) $data['tax_recoverable'] : filled($supplier->tax_number),
            'discount' => PurchaseMath::round((string) (($data['discount'] ?? null) ?: '0'), 2),
            'paid_on_post' => $mode === 'partial' ? PurchaseMath::round((string) (($data['paid_on_post'] ?? null) ?: '0'), 2) : '0',
            'cash_account_id' => $mode === 'credit' ? null : (($data['cash_account_id'] ?? null) ?: null),
            'reference' => ($data['reference'] ?? null) ?: null,
            'notes' => ($data['notes'] ?? null) ?: null,
        ];

        $itemIds = array_filter(array_column($data['lines'], 'stock_item_id'));
        $items = StockItem::with('units')->whereIn('id', $itemIds)->get()->keyBy('id');
        $lines = [];
        foreach (array_values($data['lines']) as $i => $l) {
            $n = $i + 1;
            $kind = ($l['kind'] ?? 'stock') === 'expense' ? 'expense' : 'stock';
            if ($kind === 'stock') {
                $item = $items->get((int) ($l['stock_item_id'] ?? 0));
                if (! $item || ! $item->is_stored) {
                    throw new InventoryRuleException("السطر {$n}: اختر صنفاً مخزنياً.");
                }
                if (! $item->is_active) {
                    throw new InventoryRuleException("السطر {$n}: الصنف \"{$item->name}\" موقوف.");
                }
                $unitId = (int) (($l['unit_id'] ?? 0) ?: ($item->purchase_unit_id ?? $item->base_unit_id));
                if ($unitId !== (int) $item->base_unit_id && ! $item->units->contains('unit_id', $unitId)) {
                    throw new InventoryRuleException("السطر {$n}: الوحدة المختارة غير معرّفة للصنف \"{$item->name}\".");
                }
                if (! isset($l['quantity']) || $l['quantity'] <= 0) {
                    throw new InventoryRuleException("السطر {$n}: الكمية أكبر من صفر.");
                }
                $lines[] = [
                    'kind' => 'stock', 'stock_item_id' => $item->id, 'unit_id' => $unitId, 'description' => mb_substr($item->name, 0, 255),
                    'quantity' => (string) $l['quantity'], 'unit_price' => (string) (($l['unit_price'] ?? null) ?: '0'),
                    'discount_pct' => (string) (($l['discount_pct'] ?? null) ?: '0'), 'tax_rate' => (string) (($l['tax_rate'] ?? null) ?: '0'),
                    'expiry_date' => ($l['expiry_date'] ?? null) ?: null, 'account_id' => null,
                ];
            } else {
                $account = Account::find($l['account_id'] ?? 0);
                if (! $account || ! $account->canPost()) {
                    throw new InventoryRuleException("السطر {$n}: اختر حساب المصروف (حساب يقبل القيود).");
                }
                if (trim((string) ($l['description'] ?? '')) === '') {
                    throw new InventoryRuleException("السطر {$n}: أدخل وصف المصروف.");
                }
                $lines[] = [
                    'kind' => 'expense', 'stock_item_id' => null, 'unit_id' => null, 'description' => mb_substr(trim($l['description']), 0, 255),
                    'quantity' => '1', 'unit_price' => (string) (($l['unit_price'] ?? null) ?: '0'),
                    'discount_pct' => '0', 'tax_rate' => (string) (($l['tax_rate'] ?? null) ?: '0'),
                    'expiry_date' => null, 'account_id' => $account->id,
                ];
            }
        }

        return [$header, $lines];
    }

    private function assertCanPost(PurchaseBill $bill): void
    {
        if ($bill->items->isEmpty()) {
            throw new InventoryRuleException('لا يمكن ترحيل فاتورة بلا أسطر.');
        }
        if ($bill->supplier->status === 'blocked') {
            throw new InventoryRuleException('المورّد محظور.');
        }
        if (! $bill->warehouse?->is_active) {
            throw new InventoryRuleException('المخزن المستلم موقوف أو غير محدد.');
        }
        foreach ($bill->items as $l) {
            if ($l->line_kind === 'stock' && $l->stockItem?->tracks_expiry && ! $l->expiry_date) {
                throw new InventoryRuleException("الصنف \"{$l->stockItem->name}\" يتتبع الصلاحية — أدخل تاريخ الانتهاء.");
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function mathLines($items): array
    {
        return $items->map(fn ($l) => [
            'kind' => $l->line_kind,
            'quantity' => (string) $l->quantity,
            'unit_price' => (string) $l->unit_price,
            'discount_pct' => (string) $l->discount_pct,
            'tax_rate' => (string) $l->tax_rate,
            'account_id' => $l->account_id,
        ])->all();
    }

    /** دفعة للمورّد: قيد (مدين ذمم الموردين / دائن الصندوق) + سند صرف + تحديث المدفوع وحالة الفاتورة. */
    private function recordPayment(PurchaseBill $bill, string $amount, int $cashAccountId, string $date, User $by): Voucher
    {
        $cash = Account::find($cashAccountId);
        if (! $cash || ! $cash->canPost()) {
            throw new InventoryRuleException('حساب الدفع غير صالح (يجب أن يقبل القيود).');
        }
        $remaining = bcsub((string) $bill->total, (string) $bill->paid_amount, 2);
        // الدفعة التي تُقفل الفاتورة تُقفل ذمتها بالشيكل تماماً (لا يبقى فرق تقريب سعر الصرف)
        $ils = bccomp($amount, $remaining, 2) === 0
            ? bcsub($this->payableIls($bill), $this->paidIls($bill), 2)
            : PurchaseMath::toIls($amount, (string) $bill->exchange_rate);
        $supplier = Supplier::findOrFail($bill->supplier_id);
        $balanceBefore = $this->supplierAccounting->getBalance($supplier);

        $txn = $this->runAccounting(fn () => $this->supplierAccounting->recordPayment(
            supplier: $supplier,
            amount: (float) $ils,
            cashAccountId: $cash->id,
            date: $date,
            reference: $bill->bill_number,
            branchId: $bill->branch_id,
        ));

        $voucher = Voucher::create([
            'type' => 'payment',
            'entity_type' => 'supplier',
            'entity_id' => $supplier->id,
            'entity_name' => $supplier->name,
            'purchase_bill_id' => $bill->id,
            'transaction_id' => $txn->id,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'currency' => $bill->currency,
            'payment_method_name' => $cash->name,
            'reference_number' => $bill->bill_number,
            'branch_id' => $bill->branch_id,
            'voucher_date' => $date,
            'status' => 'active',
            'notes' => "دفعة على فاتورة المشتريات {$bill->bill_number}",
            'created_by' => $by->id,
            'approved_by' => $by->id,
            'approved_at' => now(),
        ]);

        $paid = bcadd((string) $bill->paid_amount, $amount, 2);
        $bill->forceFill([
            'paid_amount' => $paid,
            'status' => bccomp($paid, (string) $bill->total, 2) >= 0 ? 'paid' : 'partially_paid',
        ])->save();

        return $voucher;
    }

    private function payableIls(PurchaseBill $bill): string
    {
        return (string) DB::table('entries')->where('transaction_id', $bill->journal_entry_id)->sum('credit');
    }

    private function paidIls(PurchaseBill $bill): string
    {
        $ids = $bill->vouchers()->where('status', 'active')->whereNotNull('transaction_id')->pluck('transaction_id');

        return (string) DB::table('entries')->whereIn('transaction_id', $ids)->sum('debit');
    }

    /**
     * قبل عكس الاستلام: هل ما زال كل صنف (ودفعته) موجوداً في المخزن؟ وإلا رسالة بكل صنف وكم ينقص.
     */
    private function assertStockSufficient(StockDocument $grn): void
    {
        $need = [];
        $batchNeed = [];
        foreach ($grn->lines as $l) {
            $factor = (int) $l->unit_id === (int) $l->stockItem->base_unit_id ? '1' : (string) ($l->stockItem->units->firstWhere('unit_id', $l->unit_id)?->factor ?? '1');
            $base = UnitConverter::toBase((string) $l->quantity, $factor);
            $need[$l->stock_item_id] = bcadd($need[$l->stock_item_id] ?? '0', $base, 6);
            if ($l->created_batch_id) {
                $batchNeed[$l->created_batch_id] = ['item' => $l->stockItem, 'qty' => $base];
            }
        }
        $short = [];
        foreach ($need as $itemId => $qty) {
            $have = (string) (DB::table('stock_balances')->where('warehouse_id', $grn->warehouse_id)->where('stock_item_id', $itemId)->value('quantity') ?? '0');
            if (bccomp($have, $qty, 6) < 0) {
                $short[] = $this->shortage($grn->lines->firstWhere('stock_item_id', $itemId)->stockItem, $qty, $have);
            }
        }
        foreach ($batchNeed as $batchId => $b) {
            $have = (string) (DB::table('stock_batch_balances')->where('warehouse_id', $grn->warehouse_id)->where('batch_id', $batchId)->value('quantity') ?? '0');
            if (bccomp($have, $b['qty'], 6) < 0) {
                $short[] = $this->shortage($b['item'], $b['qty'], $have, DB::table('stock_batches')->where('id', $batchId)->value('code'));
            }
        }
        if ($short !== []) {
            $short = array_values(array_unique($short));
            throw new InventoryRuleException('لا يمكن عكس الفاتورة — صُرف جزء من أصنافها: '.implode('، ', $short).'.', ['shortages' => $short]);
        }
    }

    private function shortage(StockItem $item, string $need, string $have, ?string $batch = null): string
    {
        $t = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');

        return sprintf('%s%s: مطلوب %s %s، المتاح %s، ينقص %s', $item->name, $batch ? " (دفعة {$batch})" : '',
            $t($need), $item->baseUnit?->name ?? '', $t($have), $t(bcsub($need, $have, 6)));
    }

    /** أخطاء المحاسبة (فترة مغلقة، حساب لا يقبل القيود...) تصل للمستخدم برسالتها، وتُفشل المعاملة كلها. */
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

    private function lockDraft(PurchaseBill $bill): PurchaseBill
    {
        $locked = PurchaseBill::whereKey($bill->id)->lockForUpdate()->firstOrFail();
        if (! $locked->is_inventory) {
            throw new InventoryRuleException('هذه فاتورة قديمة (قبل التوحيد) — للقراءة فقط.');
        }
        if ($locked->status !== 'draft') {
            throw new InventoryRuleException('الفاتورة المرحّلة لا تُعدَّل ولا تُحذف. صحّحها بعكسها.');
        }

        return $locked;
    }

    public function fresh(PurchaseBill $bill): PurchaseBill
    {
        return $bill->fresh([
            'items' => fn ($q) => $q->orderBy('line_no'),
            'items.stockItem:id,code,name,base_unit_id,purchase_unit_id,tracks_expiry', 'items.stockItem.baseUnit:id,name',
            'items.unit:id,name', 'items.account:id,code,name',
            'supplier:id,name,code,tax_number,payment_terms', 'warehouse:id,name,code,branch_id', 'cashAccount:id,code,name',
            'stockDocument:id,number,status', 'stockDocument.lines:id,stock_document_id,line_no,stock_item_id,created_batch_id',
            'stockDocument.lines.createdBatch:id,code,expiry_date',
            'vouchers:id,number,purchase_bill_id,amount,voucher_date,status,payment_method_name',
            'creator:id,name', 'poster:id,name', 'reverser:id,name',
        ]);
    }
}
