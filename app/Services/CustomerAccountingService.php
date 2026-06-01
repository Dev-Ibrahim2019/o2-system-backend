<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Transaction;
use RuntimeException;

class CustomerAccountingService
{
    public function __construct(
        private readonly TransactionPostingService $postingService,
    ) {}
 
    // ──────────────────────────────────────────────────────────────
    // 1. فاتورة العميل (Customer Invoice)
    // ──────────────────────────────────────────────────────────────
    /**
     * القيد:
     *   مدين:  1120-xxx (ذمة العميل — AR يزيد)
     *   دائن:  4110     (إيرادات المبيعات)
     */
    public function recordInvoice(
        Customer $customer,
        float    $amount,
        string   $date,
        ?float   $taxAmount  = null,
        ?string  $reference  = null,
        ?int     $branchId   = null,
    ): Transaction {
        $this->ensureAccount($customer);

        $revenueAccountId = Account::where('code', '4110')->value('id');
        $taxAccountId     = Account::where('code', '2140')->value('id');

        $entries = [
            [
                'account_id'  => $customer->account_id,
                'debit'       => $amount + ($taxAmount ?? 0),
                'credit'      => 0,
                'description' => "فاتورة مبيعات",
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
 
    // ──────────────────────────────────────────────────────────────
    // 2. دفعة من العميل (Customer Payment)
    // ──────────────────────────────────────────────────────────────
    /**
     * القيد:
     *   مدين:  1110x    (الصندوق/البنك)
     *   دائن:  1120-xxx (ذمة العميل — AR ينقص)
     */
    public function recordPayment(
        Customer $customer,
        float    $amount,
        int      $cashAccountId,
        string   $date,
        ?string  $reference = null,
        ?int     $branchId  = null,
    ): Transaction {
        $this->ensureAccount($customer);

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
                    'account_id'  => $customer->account_id,
                    'debit'       => 0,
                    'credit'      => $amount,
                    'description' => "تسوية دين العميل",
                ],
            ],
        );
    }

    private function ensureAccount(Customer $customer): void
    {
        if (! $customer->account_id) {
            throw new RuntimeException("العميل [{$customer->name}] لا يمتلك حساباً محاسبياً");
        }
    }
}
