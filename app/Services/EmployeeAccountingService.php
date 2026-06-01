<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Employee;
use App\Models\Transaction;
use RuntimeException;

class EmployeeAccountingService
{
    public function __construct(
        private readonly TransactionPostingService $postingService,
    ) {}
 
    // ──────────────────────────────────────────────────────────────
    // 1. سلفة للموظف (Employee Advance)
    // ──────────────────────────────────────────────────────────────
    /**
     * القيد:
     *   مدين:  1130-xxx  (سلف الموظف — Asset يزيد)
     *   دائن:  1110x    (الصندوق/البنك — Asset ينقص)
     *
     * المنطق: خرج مال من الصندوق وأصبح مستحقاً على الموظف
     */
    public function recordAdvance(
        Employee $employee,
        float    $amount,
        int      $cashAccountId,
        string   $date,
        ?string  $description = null,
        ?int     $branchId    = null,
    ): Transaction {
        $this->ensureAdvanceAccount($employee);
        $this->ensurePositiveAmount($amount);

        return $this->postingService->createAndPost(
            data: [
                'date'        => $date,
                'type'        => 'payment',
                'description' => $description ?? "سلفة للموظف: {$employee->name}",
                'branch_id'   => $branchId,
                'source_type' => Employee::class,
                'source_id'   => $employee->id,
                'prefix'      => 'ADV',
            ],
            entries: [
                [
                    'account_id'  => $employee->advance_account_id,
                    'debit'       => $amount,
                    'credit'      => 0,
                    'description' => "سلفة مدفوعة",
                ],
                [
                    'account_id'  => $cashAccountId,
                    'debit'       => 0,
                    'credit'      => $amount,
                    'description' => "صرف سلفة - {$employee->name}",
                ],
            ],
        );
    }
 
    // ──────────────────────────────────────────────────────────────
    // 2. سداد سلفة (Advance Repayment)
    // ──────────────────────────────────────────────────────────────
    /**
     * القيد (عكس السلفة):
     *   مدين:  1110x    (الصندوق — يزيد)
     *   دائن:  1130-xxx (سلف الموظف — ينقص)
     */
    public function recordAdvanceRepayment(
        Employee $employee,
        float    $amount,
        int      $cashAccountId,
        string   $date,
        ?string  $description = null,
        ?int     $branchId    = null,
    ): Transaction {
        $this->ensureAdvanceAccount($employee);

        $outstanding = $employee->outstanding_advance;
        if ($amount > $outstanding + 0.001) {
            throw new RuntimeException(
                "مبلغ السداد ({$amount}) أكبر من السلف المستحقة ({$outstanding})"
            );
        }

        return $this->postingService->createAndPost(
            data: [
                'date'        => $date,
                'type'        => 'receipt',
                'description' => $description ?? "سداد سلفة: {$employee->name}",
                'branch_id'   => $branchId,
                'source_type' => Employee::class,
                'source_id'   => $employee->id,
                'prefix'      => 'REPR',
            ],
            entries: [
                [
                    'account_id'  => $cashAccountId,
                    'debit'       => $amount,
                    'credit'      => 0,
                    'description' => "استرداد سلفة",
                ],
                [
                    'account_id'  => $employee->advance_account_id,
                    'debit'       => 0,
                    'credit'      => $amount,
                    'description' => "تسوية سلفة - {$employee->name}",
                ],
            ],
        );
    }
 
    // ──────────────────────────────────────────────────────────────
    // 3. استحقاق الراتب (Salary Accrual)
    // ──────────────────────────────────────────────────────────────
    /**
     * القيد:
     *   مدين:  5110     (مصروف الرواتب — Expense يزيد)
     *   دائن:  2120-xxx (راتب مستحق — Liability يزيد)
     *
     * هذا القيد يُسجَّل في نهاية الشهر قبل الدفع
     * المنطق: الراتب أصبح مستحقاً لكنه لم يُدفع بعد
     */
    public function accrualSalary(
        Employee $employee,
        float    $amount,
        string   $date,
        ?string  $description = null,
        ?int     $branchId    = null,
    ): Transaction {
        $this->ensureSalaryAccount($employee);
        $this->ensurePositiveAmount($amount);

        $salaryExpenseAccountId = Account::where('code', '5110')->value('id');

        if (! $salaryExpenseAccountId) {
            throw new RuntimeException('حساب مصروف الرواتب (5110) غير موجود');
        }

        return $this->postingService->createAndPost(
            data: [
                'date'        => $date,
                'type'        => 'salary',
                'description' => $description ?? "استحقاق راتب: {$employee->name}",
                'branch_id'   => $branchId,
                'source_type' => Employee::class,
                'source_id'   => $employee->id,
                'prefix'      => 'SAL',
            ],
            entries: [
                [
                    'account_id'  => $salaryExpenseAccountId,
                    'debit'       => $amount,
                    'credit'      => 0,
                    'description' => "مصروف راتب - {$employee->name}",
                ],
                [
                    'account_id'  => $employee->salary_account_id,
                    'debit'       => 0,
                    'credit'      => $amount,
                    'description' => "راتب مستحق",
                ],
            ],
        );
    }
 
    // ──────────────────────────────────────────────────────────────
    // 4. دفع الراتب (Salary Payment)
    // ──────────────────────────────────────────────────────────────
    /**
     * يدفع الراتب صافياً (بعد خصم السلف)
     *
     * القيد الكامل:
     *   مدين:  2120-xxx (راتب مستحق يُسدَّد)        = gross
     *   دائن:  1130-xxx (خصم السلف إن وجدت)         = advance_deduction
     *   دائن:  1110x    (نقد يُصرف للموظف)           = net_pay
     *
     * net_pay = gross - advance_deduction
     */
    public function paySalary(
        Employee $employee,
        float    $grossAmount,
        int      $cashAccountId,
        string   $date,
        float    $advanceDeduction = 0,
        ?string  $description = null,
        ?int     $branchId    = null,
    ): Transaction {
        $this->ensureSalaryAccount($employee);
        $this->ensureAdvanceAccount($employee);

        if ($advanceDeduction > 0) {
            $outstanding = $employee->outstanding_advance;
            if ($advanceDeduction > $outstanding + 0.001) {
                throw new RuntimeException(
                    "خصم السلفة ({$advanceDeduction}) أكبر من السلف المستحقة ({$outstanding})"
                );
            }
        }

        $netPay  = $grossAmount - $advanceDeduction;
        $entries = [
            [
                'account_id'  => $employee->salary_account_id,
                'debit'       => $grossAmount,
                'credit'      => 0,
                'description' => "سداد راتب",
            ],
        ];

        if ($advanceDeduction > 0) {
            $entries[] = [
                'account_id'  => $employee->advance_account_id,
                'debit'       => 0,
                'credit'      => $advanceDeduction,
                'description' => "خصم سلفة",
            ];
        }

        if ($netPay > 0) {
            $entries[] = [
                'account_id'  => $cashAccountId,
                'debit'       => 0,
                'credit'      => $netPay,
                'description' => "صافي الراتب المدفوع",
            ];
        }

        return $this->postingService->createAndPost(
            data: [
                'date'        => $date,
                'type'        => 'salary',
                'description' => $description ?? "دفع راتب: {$employee->name}",
                'branch_id'   => $branchId,
                'source_type' => Employee::class,
                'source_id'   => $employee->id,
                'prefix'      => 'PAY',
            ],
            entries: $entries,
        );
    }

    // ──────────────────────────────────────────────────────────────
    // GUARDS
    // ──────────────────────────────────────────────────────────────

    private function ensureAdvanceAccount(Employee $employee): void
    {
        if (! $employee->advance_account_id) {
            throw new RuntimeException("الموظف [{$employee->name}] لا يمتلك حساب سلف");
        }
    }

    private function ensureSalaryAccount(Employee $employee): void
    {
        if (! $employee->salary_account_id) {
            throw new RuntimeException("الموظف [{$employee->name}] لا يمتلك حساب رواتب");
        }
    }

    private function ensurePositiveAmount(float $amount): void
    {
        if ($amount <= 0) {
            throw new RuntimeException("المبلغ يجب أن يكون أكبر من صفر");
        }
    }
}
