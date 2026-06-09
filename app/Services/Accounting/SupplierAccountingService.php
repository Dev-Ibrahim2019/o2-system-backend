<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Supplier;
use App\Models\Transaction;
use RuntimeException;

class SupplierAccountingService
{
    private const AP_ACCOUNT_CODE = '2110'; // Accounts Payable

    public function __construct(
        private readonly TransactionPostingService $postingService,
        private readonly SubledgerService $subledgerService,
    ) {}

    // ──────────────────────────────────────────────────────────
    // 1. فاتورة المورد
    // ──────────────────────────────────────────────────────────
    /**
     * القيد:
     *   مدين:  expenseAccountId (حساب المشتريات)
     *   دائن:  2110 (ذمم الموردين — Control) | subledger: supplier X
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
                    'description' => "مشتريات",
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
    // 2. دفعة للمورد
    // ──────────────────────────────────────────────────────────
    /**
     * القيد:
     *   مدين:  2110 (ذمم الموردين — Control) | subledger: supplier X
     *   دائن:  11101 (الصندوق)
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
                    'description' => "صرف دفعة للمورد",
                ],
            ],
        );
    }

    public function getBalance(Supplier $supplier, ?string $asOf = null): float
    {
        return $this->subledgerService->getSupplierBalance($supplier->id, $asOf);
    }

    public function getStatement(Supplier $supplier, string $from, string $to): array
    {
        return $this->subledgerService->getStatement(
            'supplier',
            $supplier->id,
            self::AP_ACCOUNT_CODE,
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
