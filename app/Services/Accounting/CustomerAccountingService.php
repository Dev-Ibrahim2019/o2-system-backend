<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Entry;
use App\Models\Transaction;
use Carbon\Carbon;
use RuntimeException;

class CustomerAccountingService
{
    private const AR_ACCOUNT_CODE      = '1120'; // Accounts Receivable Control
    private const REVENUE_ACCOUNT_CODE = '4110';  // Sales Revenue
    private const TAX_ACCOUNT_CODE     = '2140';  // Output Tax (VAT)
    private const DISCOUNT_ACCOUNT_CODE = '4120'; // Sales Discount

    public function __construct(
        private readonly TransactionPostingService $postingService,
        private readonly SubledgerService $subledgerService,
    ) {}

    // ──────────────────────────────────────────────────────────
    // 1. Sales Invoice (فاتورة مبيعات)
    // ──────────────────────────────────────────────────────────
    /**
     * Journal Entry:
     *   Dr: 1120 (AR Control) | subledger: customer X
     *   Cr: 4110 (Sales Revenue)
     *   Cr: 2140 (Output Tax) if taxable
     */
    public function recordInvoice(
        Customer $customer,
        float    $amount,
        string   $date,
        ?float   $taxAmount  = null,
        ?string  $reference  = null,
        ?int     $branchId   = null,
    ): Transaction {
        $arAccountId      = $this->getAccountId(self::AR_ACCOUNT_CODE);
        $revenueAccountId = $this->getAccountId(self::REVENUE_ACCOUNT_CODE);
        $taxAccountId     = Account::where('code', self::TAX_ACCOUNT_CODE)->value('id');

        $totalAmount = $amount + ($taxAmount ?? 0);

        $entries = [
            [
                'account_id'     => $arAccountId,
                'debit'          => $totalAmount,
                'credit'         => 0,
                'description'    => "فاتورة مبيعات - {$customer->name}",
                'subledger_type' => 'customer',
                'subledger_id'   => $customer->id,
            ],
            [
                'account_id'  => $revenueAccountId,
                'debit'       => 0,
                'credit'      => $amount,
                'description' => "إيراد مبيعات",
            ],
        ];

        if ($taxAmount && $taxAmount > 0 && $taxAccountId) {
            $entries[] = [
                'account_id'  => $taxAccountId,
                'debit'       => 0,
                'credit'      => $taxAmount,
                'description' => "ضريبة القيمة المضافة",
            ];
        }

        return $this->postingService->createAndPost(
            data: [
                'date'        => $date,
                'type'        => 'sale',
                'reference'   => $reference,
                'description' => "فاتورة مبيعات: {$customer->name}",
                'branch_id'   => $branchId,
                'source_type' => Customer::class,
                'source_id'   => $customer->id,
                'prefix'      => 'INV',
            ],
            entries: $entries,
        );
    }

    // ──────────────────────────────────────────────────────────
    // 2. Customer Receipt (دفعة من العميل)
    // ──────────────────────────────────────────────────────────
    /**
     * Journal Entry:
     *   Dr: cashAccountId (Cash/Bank)
     *   Cr: 1120 (AR Control) | subledger: customer X
     */
    public function recordPayment(
        Customer $customer,
        float    $amount,
        int      $cashAccountId,
        string   $date,
        ?string  $reference = null,
        ?int     $branchId  = null,
    ): Transaction {
        $arAccountId = $this->getAccountId(self::AR_ACCOUNT_CODE);

        return $this->postingService->createAndPost(
            data: [
                'date'        => $date,
                'type'        => 'receipt',
                'reference'   => $reference,
                'description' => "دفعة من العميل: {$customer->name}",
                'branch_id'   => $branchId,
                'source_type' => Customer::class,
                'source_id'   => $customer->id,
                'prefix'      => 'RCP',
            ],
            entries: [
                [
                    'account_id'  => $cashAccountId,
                    'debit'       => $amount,
                    'credit'      => 0,
                    'description' => "استلام دفعة من العميل",
                ],
                [
                    'account_id'     => $arAccountId,
                    'debit'          => 0,
                    'credit'         => $amount,
                    'description'    => "تسوية ذمة العميل: {$customer->name}",
                    'subledger_type' => 'customer',
                    'subledger_id'   => $customer->id,
                ],
            ],
        );
    }

    // ──────────────────────────────────────────────────────────
    // 3. Credit Note (إشعار دائن)
    // ──────────────────────────────────────────────────────────
    /**
     * Journal Entry:
     *   Dr: 1120 (AR Control) | subledger: customer X
     *   Cr: 4110 (Sales Revenue) - reduction
     */
    public function recordCreditNote(
        Customer $customer,
        float    $amount,
        int      $revenueAccountId,
        string   $date,
        ?string  $reference = null,
        ?int     $branchId  = null,
    ): Transaction {
        $arAccountId = $this->getAccountId(self::AR_ACCOUNT_CODE);

        return $this->postingService->createAndPost(
            data: [
                'date'        => $date,
                'type'        => 'adjustment',
                'reference'   => $reference,
                'description' => "إشعار دائن للعميل: {$customer->name}",
                'branch_id'   => $branchId,
                'source_type' => Customer::class,
                'source_id'   => $customer->id,
                'prefix'      => 'CN',
            ],
            entries: [
                [
                    'account_id'     => $arAccountId,
                    'debit'          => 0,
                    'credit'         => $amount,
                    'description'    => "إشعار دائن - {$customer->name}",
                    'subledger_type' => 'customer',
                    'subledger_id'   => $customer->id,
                ],
                [
                    'account_id'  => $revenueAccountId,
                    'debit'       => $amount,
                    'credit'      => 0,
                    'description' => "إشعار دائن - مبيعات {$customer->name}",
                ],
            ],
        );
    }

    // ──────────────────────────────────────────────────────────
    // 4. Debit Note (إشعار مدين)
    // ──────────────────────────────────────────────────────────
    /**
     * Journal Entry:
     *   Dr: 1120 (AR Control) | subledger: customer X
     *   Cr: 4110 (Sales Revenue) - additional charge
     */
    public function recordDebitNote(
        Customer $customer,
        float    $amount,
        int      $revenueAccountId,
        string   $date,
        ?string  $reference = null,
        ?int     $branchId  = null,
    ): Transaction {
        $arAccountId = $this->getAccountId(self::AR_ACCOUNT_CODE);

        return $this->postingService->createAndPost(
            data: [
                'date'        => $date,
                'type'        => 'adjustment',
                'reference'   => $reference,
                'description' => "إشعار مدين للعميل: {$customer->name}",
                'branch_id'   => $branchId,
                'source_type' => Customer::class,
                'source_id'   => $customer->id,
                'prefix'      => 'DN',
            ],
            entries: [
                [
                    'account_id'     => $arAccountId,
                    'debit'          => $amount,
                    'credit'         => 0,
                    'description'    => "إشعار مدين - {$customer->name}",
                    'subledger_type' => 'customer',
                    'subledger_id'   => $customer->id,
                ],
                [
                    'account_id'  => $revenueAccountId,
                    'debit'       => 0,
                    'credit'      => $amount,
                    'description' => "إشعار مدين - مبيعات {$customer->name}",
                ],
            ],
        );
    }

    // ──────────────────────────────────────────────────────────
    // 5. Opening Balance (الرصيد الافتتاحي)
    // ──────────────────────────────────────────────────────────
    /**
     * Journal Entry:
     *   Dr: 1120 (AR Control) | subledger: customer X
     *   Cr: 3999 (Opening Balance Equity)
     */
    public function postOpeningBalance(
        Customer $customer,
        float    $amount,
        int      $openingBalanceAccountId,
        string   $date,
        ?int     $branchId = null,
    ): Transaction {
        $arAccountId = $this->getAccountId(self::AR_ACCOUNT_CODE);

        $transaction = $this->postingService->createAndPost(
            data: [
                'date'        => $date,
                'type'        => 'opening',
                'description' => "رصيد افتتاحي للعميل: {$customer->name}",
                'branch_id'   => $branchId,
                'source_type' => Customer::class,
                'source_id'   => $customer->id,
                'prefix'      => 'OPB',
            ],
            entries: [
                [
                    'account_id'     => $arAccountId,
                    'debit'          => $amount,
                    'credit'         => 0,
                    'description'    => "رصيد افتتاحي - {$customer->name}",
                    'subledger_type' => 'customer',
                    'subledger_id'   => $customer->id,
                ],
                [
                    'account_id'  => $openingBalanceAccountId,
                    'debit'       => 0,
                    'credit'      => $amount,
                    'description' => "رصيد افتتاحي عملاء - {$customer->name}",
                ],
            ],
        );

        $customer->update(['is_opening_balance_posted' => true]);

        return $transaction;
    }

    // ──────────────────────────────────────────────────────────
    // 6. Write-Off (شطب دين)
    // ──────────────────────────────────────────────────────────
    /**
     * Journal Entry:
     *   Dr: 5190 (Bad Debt Expense)
     *   Cr: 1120 (AR Control) | subledger: customer X
     */
    public function recordWriteOff(
        Customer $customer,
        float    $amount,
        int      $badDebtAccountId,
        string   $date,
        ?string  $reason = null,
        ?int     $branchId = null,
    ): Transaction {
        $arAccountId = $this->getAccountId(self::AR_ACCOUNT_CODE);

        return $this->postingService->createAndPost(
            data: [
                'date'        => $date,
                'type'        => 'write_off',
                'description' => "شطب دين العميل: {$customer->name} - {$reason}",
                'branch_id'   => $branchId,
                'source_type' => Customer::class,
                'source_id'   => $customer->id,
                'prefix'      => 'WO',
            ],
            entries: [
                [
                    'account_id'     => $arAccountId,
                    'debit'          => 0,
                    'credit'         => $amount,
                    'description'    => "شطب دين - {$customer->name}",
                    'subledger_type' => 'customer',
                    'subledger_id'   => $customer->id,
                ],
                [
                    'account_id'  => $badDebtAccountId,
                    'debit'       => $amount,
                    'credit'      => 0,
                    'description' => "مصروف ديون معدومة - {$customer->name}",
                ],
            ],
        );
    }

    // ──────────────────────────────────────────────────────────
    // 7. Customer Statement (كشف حساب)
    // ──────────────────────────────────────────────────────────
    public function getStatement(
        Customer $customer,
        string   $from,
        string   $to,
        ?int     $branchId = null,
    ): array {
        return $this->subledgerService->getFullStatement(
            type: 'customer',
            id: $customer->id,
            from: $from,
            to: $to,
            branchId: $branchId,
        );
    }

    // ──────────────────────────────────────────────────────────
    // 8. Aging Analysis (تحليل الأعمار)
    // ──────────────────────────────────────────────────────────
    public function getAging(Customer $customer, ?string $asOf = null): array
    {
        $asOfDate = $asOf ? Carbon::parse($asOf) : Carbon::today();

        $entries = Entry::query()
            ->forSubledger('customer', $customer->id)
            ->whereHas('transaction', function ($q) use ($asOfDate) {
                $q->where('status', 'posted')
                    ->where('date', '<=', $asOfDate);
            })
            ->with('transaction:id,date,type,transaction_number')
            ->orderBy('id')
            ->get();

        $buckets = [
            'current' => 0,
            '1_30'    => 0,
            '31_60'   => 0,
            '61_90'   => 0,
            'over_90' => 0,
            'total'   => 0,
        ];

        $arAccountId = $this->getAccountId(self::AR_ACCOUNT_CODE);

        foreach ($entries as $entry) {
            if ((int) $entry->account_id !== $arAccountId) continue;

            // For AR: debit = increase in receivable, credit = decrease
            $net = (float) $entry->debit - (float) $entry->credit;
            if ($net <= 0) continue; // only outstanding balances

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
    // 9. Current Balance
    // ──────────────────────────────────────────────────────────
    public function getBalance(Customer $customer, ?string $asOf = null): float
    {
        return $this->subledgerService->getCustomerBalance($customer->id, $asOf);
    }

    // ──────────────────────────────────────────────────────────
    // 10. Monthly Collections
    // ──────────────────────────────────────────────────────────
    public function getMonthlyCollections(Customer $customer): float
    {
        $now = Carbon::today();
        $from = $now->copy()->startOfMonth()->toDateString();
        $to = $now->toDateString();

        return $this->subledgerService->getPaymentsTotal(
            type: 'customer',
            id: $customer->id,
            from: $from,
            to: $to,
        );
    }

    // ── Helpers ───────────────────────────────────────────────

    private function getAccountId(string $code): int
    {
        $id = Account::where('code', $code)->value('id');
        if (!$id) throw new RuntimeException("حساب ({$code}) غير موجود");
        return $id;
    }
}
