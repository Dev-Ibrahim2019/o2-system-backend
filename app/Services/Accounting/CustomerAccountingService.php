<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Transaction;
use RuntimeException;

class CustomerAccountingService
{
    private const AR_ACCOUNT_CODE      = '1120'; // Accounts Receivable
    private const REVENUE_ACCOUNT_CODE = '4110';
    private const TAX_ACCOUNT_CODE     = '2140';

    public function __construct(
        private readonly TransactionPostingService $postingService,
        private readonly SubledgerService $subledgerService,
    ) {}

    // ──────────────────────────────────────────────────────────
    // 1. فاتورة العميل
    // ──────────────────────────────────────────────────────────
    /**
     * القيد:
     *   مدين:  1120 (ذمم العملاء — Control) | subledger: customer X
     *   دائن:  4110 (إيرادات المبيعات)
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
    // 2. دفعة من العميل
    // ──────────────────────────────────────────────────────────
    /**
     * القيد:
     *   مدين:  11101 (الصندوق)
     *   دائن:  1120 (ذمم العملاء — Control) | subledger: customer X
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
                    'description' => "استلام دفعة",
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

    /**
     * رصيد العميل الحالي
     */
    public function getBalance(Customer $customer, ?string $asOf = null): float
    {
        return $this->subledgerService->getCustomerBalance($customer->id, $asOf);
    }

    /**
     * كشف حساب العميل
     */
    public function getStatement(Customer $customer, string $from, string $to): array
    {
        return $this->subledgerService->getStatement(
            'customer',
            $customer->id,
            self::AR_ACCOUNT_CODE,
            $from,
            $to
        );
    }

    private function getAccountId(string $code): int
    {
        $id = Account::where('code', $code)->value('id');
        if (! $id) throw new RuntimeException("حساب ({$code}) غير موجود");
        return $id;
    }
}
