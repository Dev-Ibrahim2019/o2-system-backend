<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Supplier;
use App\Models\Transaction;
use RuntimeException;

class SupplierAccountingService
{
    public function __construct(
        private readonly TransactionPostingService $postingService,
    ) {}
 
    // ──────────────────────────────────────────────────────────────
    // 1. فاتورة المورد / مشتريات (Supplier Bill)
    // ──────────────────────────────────────────────────────────────
    /**
     * القيد:
     *   مدين:  5xxx     (حساب المشتريات/المصروف)
     *   دائن:  2110-xxx (ذمة المورد — AP يزيد)
     */
    public function recordBill(
        Supplier $supplier,
        float    $amount,
        int      $expenseAccountId,
        string   $date,
        ?string  $reference = null,
        ?int     $branchId  = null,
    ): Transaction {
        $this->ensureAccount($supplier);

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
                    'account_id'  => $supplier->account_id,
                    'debit'       => 0,
                    'credit'      => $amount,
                    'description' => "دين المورد",
                ],
            ],
        );
    }
 
    // ──────────────────────────────────────────────────────────────
    // 2. دفعة للمورد (Supplier Payment)
    // ──────────────────────────────────────────────────────────────
    /**
     * القيد:
     *   مدين:  2110-xxx (ذمة المورد — AP ينقص)
     *   دائن:  1110x    (الصندوق/البنك)
     */
    public function recordPayment(
        Supplier $supplier,
        float    $amount,
        int      $cashAccountId,
        string   $date,
        ?string  $reference = null,
        ?int     $branchId  = null,
    ): Transaction {
        $this->ensureAccount($supplier);

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
                    'account_id'  => $supplier->account_id,
                    'debit'       => $amount,
                    'credit'      => 0,
                    'description' => "تسوية دين المورد",
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

    private function ensureAccount(Supplier $supplier): void
    {
        if (! $supplier->account_id) {
            throw new RuntimeException("المورد [{$supplier->name}] لا يمتلك حساباً محاسبياً");
        }
    }
}
