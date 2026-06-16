<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Supplier;
use App\Models\Transaction;
use Carbon\Carbon;
use RuntimeException;

class SupplierAccountingService
{
    private const AP_ACCOUNT_CODE = '2110'; // Accounts Payable

    public function __construct(
        private readonly TransactionPostingService $postingService,
        private readonly SubledgerService $subledgerService,
    ) {}

    // ──────────────────────────────────────────────────────────
    // 1. فاتورة المورد (Purchase Invoice)
    // ──────────────────────────────────────────────────────────
    /**
     * القيد:
     *   مدين:  expenseAccountId (حساب المصروف/المشتريات)
     *   دائن:  2110 (ذمم الموردين) | subledger: supplier X
     */
    public function recordBill(
        Supplier $supplier,
        float    $amount,
        int      $expenseAccountId,
        string   $date,
        ?string  $reference = null,
        ?int     $branchId  = null,
    ): Transaction {
        $apAccountId = $this->getAccountId(self::AP_ACCOUNT_CODE);

        return $this->postingService->createAndPost(
            data: [
                'date'        => $date,
                'type'        => 'purchase',
                'reference'   => $reference,
                'description' => "فاتورة مورد: {$supplier->name}",
                'branch_id'   => $branchId,
                'source_type' => Supplier::class,
                'source_id'   => $supplier->id,
                'prefix'      => 'BILL',
            ],
            entries: [
                [
                    'account_id'  => $expenseAccountId,
                    'debit'       => $amount,
                    'credit'      => 0,
                    'description' => "مشتريات من {$supplier->name}",
                ],
                [
                    'account_id'     => $apAccountId,
                    'debit'          => 0,
                    'credit'         => $amount,
                    'description'    => "دين المورد: {$supplier->name}",
                    'subledger_type' => 'supplier',
                    'subledger_id'   => $supplier->id,
                ],
            ],
        );
    }

    // ──────────────────────────────────────────────────────────
    // 2. دفعة للمورد (Supplier Payment)
    // ──────────────────────────────────────────────────────────
    /**
     * القيد:
     *   مدين:  2110 (ذمم الموردين) | subledger: supplier X
     *   دائن:  cashAccountId (الصندوق/البنك)
     */
    public function recordPayment(
        Supplier $supplier,
        float    $amount,
        int      $cashAccountId,
        string   $date,
        ?string  $reference = null,
        ?int     $branchId  = null,
    ): Transaction {
        $apAccountId = $this->getAccountId(self::AP_ACCOUNT_CODE);

        return $this->postingService->createAndPost(
            data: [
                'date'        => $date,
                'type'        => 'payment',
                'reference'   => $reference,
                'description' => "دفعة للمورد: {$supplier->name}",
                'branch_id'   => $branchId,
                'source_type' => Supplier::class,
                'source_id'   => $supplier->id,
                'prefix'      => 'PMT',
            ],
            entries: [
                [
                    'account_id'     => $apAccountId,
                    'debit'          => $amount,
                    'credit'         => 0,
                    'description'    => "تسوية دين المورد: {$supplier->name}",
                    'subledger_type' => 'supplier',
                    'subledger_id'   => $supplier->id,
                ],
                [
                    'account_id'  => $cashAccountId,
                    'debit'       => 0,
                    'credit'      => $amount,
                    'description' => "صرف دفعة للمورد: {$supplier->name}",
                ],
            ],
        );
    }

    // ──────────────────────────────────────────────────────────
    // 3. إشعار دائن (Credit Note) — تقليل الدين
    // ──────────────────────────────────────────────────────────
    /**
     * القيد:
     *   مدين:  2110 (ذمم الموردين) | subledger: supplier X
     *   دائن:  expenseAccountId (حساب المشتريات)
     */
    public function recordCreditNote(
        Supplier $supplier,
        float    $amount,
        int      $expenseAccountId,
        string   $date,
        ?string  $reference = null,
        ?int     $branchId  = null,
    ): Transaction {
        $apAccountId = $this->getAccountId(self::AP_ACCOUNT_CODE);

        return $this->postingService->createAndPost(
            data: [
                'date'        => $date,
                'type'        => 'adjustment',
                'reference'   => $reference,
                'description' => "إشعار دائن من المورد: {$supplier->name}",
                'branch_id'   => $branchId,
                'source_type' => Supplier::class,
                'source_id'   => $supplier->id,
                'prefix'      => 'CN',
            ],
            entries: [
                [
                    'account_id'     => $apAccountId,
                    'debit'          => $amount,
                    'credit'         => 0,
                    'description'    => "إشعار دائن - {$supplier->name}",
                    'subledger_type' => 'supplier',
                    'subledger_id'   => $supplier->id,
                ],
                [
                    'account_id'  => $expenseAccountId,
                    'debit'       => 0,
                    'credit'      => $amount,
                    'description' => "إشعار دائن - مشتريات {$supplier->name}",
                ],
            ],
        );
    }

    // ──────────────────────────────────────────────────────────
    // 4. إشعار مدين (Debit Note) — زيادة الدين
    // ──────────────────────────────────────────────────────────
    /**
     * القيد:
     *   مدين:  expenseAccountId (حساب المشتريات)
     *   دائن:  2110 (ذمم الموردين) | subledger: supplier X
     */
    public function recordDebitNote(
        Supplier $supplier,
        float    $amount,
        int      $expenseAccountId,
        string   $date,
        ?string  $reference = null,
        ?int     $branchId  = null,
    ): Transaction {
        $apAccountId = $this->getAccountId(self::AP_ACCOUNT_CODE);

        return $this->postingService->createAndPost(
            data: [
                'date'        => $date,
                'type'        => 'adjustment',
                'reference'   => $reference,
                'description' => "إشعار مدين للمورد: {$supplier->name}",
                'branch_id'   => $branchId,
                'source_type' => Supplier::class,
                'source_id'   => $supplier->id,
                'prefix'      => 'DN',
            ],
            entries: [
                [
                    'account_id'  => $expenseAccountId,
                    'debit'       => $amount,
                    'credit'      => 0,
                    'description' => "إشعار مدين - مشتريات {$supplier->name}",
                ],
                [
                    'account_id'     => $apAccountId,
                    'debit'          => 0,
                    'credit'         => $amount,
                    'description'    => "إشعار مدين - {$supplier->name}",
                    'subledger_type' => 'supplier',
                    'subledger_id'   => $supplier->id,
                ],
            ],
        );
    }

    // ──────────────────────────────────────────────────────────
    // 5. ترحيل الرصيد الافتتاحي (Opening Balance)
    // ──────────────────────────────────────────────────────────
    /**
     * القيد:
     *   مدين:  2110 (ذمم الموردين — رصيد افتتاحي) | subledger: supplier X
     *   دائن:  openingBalanceAccountId (حساب أرصدة افتتاحية)
     */
    public function postOpeningBalance(
        Supplier $supplier,
        float    $amount,
        int      $openingBalanceAccountId,
        string   $date,
        ?int     $branchId = null,
    ): Transaction {
        $apAccountId = $this->getAccountId(self::AP_ACCOUNT_CODE);

        $transaction = $this->postingService->createAndPost(
            data: [
                'date'        => $date,
                'type'        => 'opening',
                'description' => "رصيد افتتاحي للمورد: {$supplier->name}",
                'branch_id'   => $branchId,
                'source_type' => Supplier::class,
                'source_id'   => $supplier->id,
                'prefix'      => 'OPB',
            ],
            entries: [
                [
                    'account_id'     => $apAccountId,
                    'debit'          => 0,
                    'credit'         => $amount,
                    'description'    => "رصيد افتتاحي - {$supplier->name}",
                    'subledger_type' => 'supplier',
                    'subledger_id'   => $supplier->id,
                ],
                [
                    'account_id'  => $openingBalanceAccountId,
                    'debit'       => $amount,
                    'credit'      => 0,
                    'description' => "رصيد افتتاحي موردين - {$supplier->name}",
                ],
            ],
        );

        $supplier->update(['is_opening_balance_posted' => true]);

        return $transaction;
    }

    // ──────────────────────────────────────────────────────────
    // 6. كشف حساب المورد (Statement)
    // ──────────────────────────────────────────────────────────
    /**
     * كشف حساب المورد — يستخدم getFullStatement لجلب ALL entries
     * عبر subledger_type = 'supplier' + subledger_id
     * وليس فقط حسب حساب 2110
     */
    public function getStatement(
        Supplier $supplier,
        string   $from,
        string   $to,
        ?int     $branchId = null,
    ): array {
        return $this->subledgerService->getFullStatement(
            type: 'supplier',
            id: $supplier->id,
            from: $from,
            to: $to,
            branchId: $branchId,
        );
    }

    // ──────────────────────────────────────────────────────────
    // 7. تحليل الأعمار (Aging Analysis)
    // ──────────────────────────────────────────────────────────
    public function getAging(Supplier $supplier, ?string $asOf = null): array
    {
        $asOfDate = $asOf ? Carbon::parse($asOf) : Carbon::today();

        $entries = \App\Models\Entry::query()
            ->forSubledger('supplier', $supplier->id)
            ->whereHas('transaction', function ($q) use ($asOfDate) {
                $q->where('status', 'posted')
                    ->where('date', '<=', $asOfDate);
            })
            ->with('transaction:id,date,type')
            ->orderBy('id')
            ->get();

        $buckets = [
            'current'     => 0,
            '1_30'        => 0,
            '31_60'       => 0,
            '61_90'       => 0,
            'over_90'     => 0,
            'total'       => 0,
        ];

        $apAccountId = $this->getAccountId(self::AP_ACCOUNT_CODE);

        foreach ($entries as $entry) {
            if ((int) $entry->account_id !== $apAccountId) continue;

            $net = (float) $entry->credit - (float) $entry->debit;
            if ($net <= 0) continue; // only unpaid balances

            $days = $asOfDate->diffInDays(Carbon::parse($entry->transaction->date));
            $buckets['total'] += $net;

            if ($days <= 0)       $buckets['current'] += $net;
            elseif ($days <= 30)  $buckets['1_30'] += $net;
            elseif ($days <= 60)  $buckets['31_60'] += $net;
            elseif ($days <= 90)  $buckets['61_90'] += $net;
            else                  $buckets['over_90'] += $net;
        }

        return $buckets;
    }

    // ──────────────────────────────────────────────────────────
    // ✅ 8. إجمالي المدفوعات الشهرية للمورد
    // ──────────────────────────────────────────────────────────
    /**
     * إجمالي المدفوعات المدفوعة لهذا المورد في الشهر الحالي
     */
    public function getMonthlyPayments(Supplier $supplier): float
    {
        $now = Carbon::today();
        $from = $now->copy()->startOfMonth()->toDateString();
        $to = $now->toDateString();

        return $this->subledgerService->getPaymentsTotal(
            type: 'supplier',
            id: $supplier->id,
            from: $from,
            to: $to,
        );
    }

    // ──────────────────────────────────────────────────────────
    // 9. الرصيد الحالي
    // ──────────────────────────────────────────────────────────
    public function getBalance(Supplier $supplier, ?string $asOf = null): float
    {
        return $this->subledgerService->getSupplierBalance($supplier->id, $asOf);
    }

    // ── Helpers ───────────────────────────────────────────────

    private function getAccountId(string $code): int
    {
        $id = Account::where('code', $code)->value('id');
        if (! $id) throw new RuntimeException("حساب ({$code}) غير موجود");
        return $id;
    }
}
