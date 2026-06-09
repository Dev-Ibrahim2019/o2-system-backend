<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Employee;
use App\Models\Transaction;
use RuntimeException;

/**
 * ══════════════════════════════════════════════════════════════
 * SERVICE: EmployeeAccountingService (Subledger Version)
 * ══════════════════════════════════════════════════════════════
 *
 * الفرق عن النسخة القديمة:
 *
 * قديم:
 *   entries: account_id = 1130-0033 (حساب خاص بالموظف)
 *
 * جديد:
 *   entries: account_id = 1130 (Control Account مشترك)
 *            subledger_type = 'employee'
 *            subledger_id   = 33
 *
 * النتيجة:
 * - Chart of Accounts يبقى نظيفاً (حسابان فقط للكل)
 * - كشف حساب أي موظف بـ query بسيطة على entries
 * ══════════════════════════════════════════════════════════════
 */
class EmployeeAccountingService
{
    // الأكواد الثابتة لحسابات التحكم
    private const ADVANCE_ACCOUNT_CODE       = '1130';  // Asset
    private const SALARY_PAYABLE_ACCOUNT_CODE = '2120';  // Liability
    private const SALARY_EXPENSE_ACCOUNT_CODE = '5110';  // Expense

    public function __construct(
        private readonly TransactionPostingService $postingService,
        private readonly SubledgerService $subledgerService,
    ) {}

    // ──────────────────────────────────────────────────────────
    // 1. سلفة للموظف (Employee Advance)
    // ──────────────────────────────────────────────────────────
    /**
     * القيد:
     *   مدين:  1130 (سلف الموظفين — Control) | subledger: employee 33
     *   دائن:  11101 (الصندوق)
     */
    public function recordAdvance(
        Employee $employee,
        float    $amount,
        int      $cashAccountId,
        string   $date,
        ?string  $description = null,
        ?int     $branchId    = null,
    ): Transaction {
        $this->ensurePositiveAmount($amount);

        $advanceAccountId = $this->getAccountId(self::ADVANCE_ACCOUNT_CODE);

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
                    'account_id'     => $advanceAccountId,
                    'debit'          => $amount,
                    'credit'         => 0,
                    'description'    => "سلفة للموظف: {$employee->name}",
                    // ── Subledger ──
                    'subledger_type' => 'employee',
                    'subledger_id'   => $employee->id,
                ],
                [
                    'account_id'  => $cashAccountId,
                    'debit'       => 0,
                    'credit'      => $amount,
                    'description' => "صرف سلفة - {$employee->name}",
                    // الصندوق لا يحتاج subledger
                ],
            ],
        );
    }

    // ──────────────────────────────────────────────────────────
    // 2. سداد سلفة (Advance Repayment)
    // ──────────────────────────────────────────────────────────
    /**
     * القيد:
     *   مدين:  11101 (الصندوق)
     *   دائن:  1130 (سلف الموظفين) | subledger: employee 33
     */
    public function recordAdvanceRepayment(
        Employee $employee,
        float    $amount,
        int      $cashAccountId,
        string   $date,
        ?string  $description = null,
        ?int     $branchId    = null,
    ): Transaction {
        // التحقق أن السداد لا يتجاوز السلف المستحقة
        $outstanding = $this->subledgerService->getBalance(
            'employee',
            $employee->id,
            self::ADVANCE_ACCOUNT_CODE
        );

        if ($amount > $outstanding + 0.001) {
            throw new RuntimeException(
                "مبلغ السداد ({$amount}) أكبر من السلف المستحقة ({$outstanding})"
            );
        }

        $advanceAccountId = $this->getAccountId(self::ADVANCE_ACCOUNT_CODE);

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
                    'description' => "استرداد سلفة من {$employee->name}",
                ],
                [
                    'account_id'     => $advanceAccountId,
                    'debit'          => 0,
                    'credit'         => $amount,
                    'description'    => "تسوية سلفة - {$employee->name}",
                    'subledger_type' => 'employee',
                    'subledger_id'   => $employee->id,
                ],
            ],
        );
    }

    // ──────────────────────────────────────────────────────────
    // 3. استحقاق الراتب (Salary Accrual)
    // ──────────────────────────────────────────────────────────
    /**
     * القيد:
     *   مدين:  5110 (مصروف الرواتب)
     *   دائن:  2120 (رواتب مستحقة — Control) | subledger: employee 33
     *
     * يُسجَّل في نهاية الشهر قبل الدفع
     */
    public function accrualSalary(
        Employee $employee,
        float    $amount,
        string   $date,
        ?string  $description = null,
        ?int     $branchId    = null,
    ): Transaction {
        $this->ensurePositiveAmount($amount);

        $salaryExpenseId  = $this->getAccountId(self::SALARY_EXPENSE_ACCOUNT_CODE);
        $salaryPayableId  = $this->getAccountId(self::SALARY_PAYABLE_ACCOUNT_CODE);

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
                    'account_id'  => $salaryExpenseId,
                    'debit'       => $amount,
                    'credit'      => 0,
                    'description' => "مصروف راتب - {$employee->name}",
                    // مصروف الرواتب حساب إجمالي — لا يحتاج subledger
                    // (يمكن إضافته إذا أردت تقرير مصاريف رواتب بالموظف)
                    'subledger_type' => 'employee',
                    'subledger_id'   => $employee->id,
                ],
                [
                    'account_id'     => $salaryPayableId,
                    'debit'          => 0,
                    'credit'         => $amount,
                    'description'    => "راتب مستحق - {$employee->name}",
                    'subledger_type' => 'employee',
                    'subledger_id'   => $employee->id,
                ],
            ],
        );
    }

    // ──────────────────────────────────────────────────────────
    // 4. دفع الراتب (Salary Payment)
    // ──────────────────────────────────────────────────────────
    /**
     * القيد الكامل:
     *   مدين:  2120 (رواتب مستحقة) | subledger: employee 33  = gross
     *   دائن:  1130 (سلف الموظفين) | subledger: employee 33  = advance_deduction
     *   دائن:  11101 (نقدية)                                  = net_pay
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
        // التحقق من كفاية الرصيد قبل الدفع
        $accrued = $this->subledgerService->getBalance(
            'employee',
            $employee->id,
            self::SALARY_PAYABLE_ACCOUNT_CODE
        );

        if ($grossAmount > $accrued + 0.001) {
            throw new RuntimeException(
                "المبلغ المدفوع ({$grossAmount}) أكبر من الراتب المستحق ({$accrued}). " .
                    "يرجى تسجيل استحقاق الراتب أولاً."
            );
        }

        if ($advanceDeduction > 0) {
            $outstanding = $this->subledgerService->getBalance(
                'employee',
                $employee->id,
                self::ADVANCE_ACCOUNT_CODE
            );

            if ($advanceDeduction > $outstanding + 0.001) {
                throw new RuntimeException(
                    "خصم السلفة ({$advanceDeduction}) أكبر من السلف المستحقة ({$outstanding})"
                );
            }
        }

        $salaryPayableId = $this->getAccountId(self::SALARY_PAYABLE_ACCOUNT_CODE);
        $advanceAccountId = $this->getAccountId(self::ADVANCE_ACCOUNT_CODE);
        $netPay = $grossAmount - $advanceDeduction;

        $entries = [
            [
                'account_id'     => $salaryPayableId,
                'debit'          => $grossAmount,
                'credit'         => 0,
                'description'    => "سداد راتب - {$employee->name}",
                'subledger_type' => 'employee',
                'subledger_id'   => $employee->id,
            ],
        ];

        if ($advanceDeduction > 0) {
            $entries[] = [
                'account_id'     => $advanceAccountId,
                'debit'          => 0,
                'credit'         => $advanceDeduction,
                'description'    => "خصم سلفة من الراتب - {$employee->name}",
                'subledger_type' => 'employee',
                'subledger_id'   => $employee->id,
            ];
        }

        if ($netPay > 0) {
            $entries[] = [
                'account_id'  => $cashAccountId,
                'debit'       => 0,
                'credit'      => $netPay,
                'description' => "صافي الراتب المدفوع - {$employee->name}",
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

    // ── Private Helpers ───────────────────────────────────────────────────────

    private function getAccountId(string $code): int
    {
        $id = Account::where('code', $code)->value('id');

        if (! $id) {
            throw new RuntimeException("حساب التحكم ({$code}) غير موجود — تأكد من تشغيل ChartOfAccountsSeeder");
        }

        return $id;
    }

    private function ensurePositiveAmount(float $amount): void
    {
        if ($amount <= 0) {
            throw new RuntimeException("المبلغ يجب أن يكون أكبر من صفر");
        }
    }
}
