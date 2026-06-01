<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\Employee;
use App\Services\Accounting\EmployeeAccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeFinancialController extends ApiController
{
    public function __construct(
        private readonly EmployeeAccountingService $employeeService,
    ) {}

    /**
     * POST /api/employees/{employee}/advance
     */
    public function recordAdvance(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'amount'          => ['required', 'numeric', 'min:0.001'],
            'cash_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'date'            => ['required', 'date'],
            'description'     => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $transaction = $this->employeeService->recordAdvance(
                employee: $employee,
                amount: $data['amount'],
                cashAccountId: $data['cash_account_id'],
                date: $data['date'],
                description: $data['description'] ?? null,
                branchId: $request->user()?->branch_id,
            );

            return $this->success('تم تسجيل السلفة بنجاح', [
                'transaction'        => $transaction,
                'outstanding_advance' => $employee->fresh()->outstanding_advance,
            ], 201);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/employees/{employee}/advance-repayment
     */
    public function recordAdvanceRepayment(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'amount'          => ['required', 'numeric', 'min:0.001'],
            'cash_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'date'            => ['required', 'date'],
            'description'     => ['nullable', 'string'],
        ]);

        try {
            $transaction = $this->employeeService->recordAdvanceRepayment(
                employee: $employee,
                amount: $data['amount'],
                cashAccountId: $data['cash_account_id'],
                date: $data['date'],
                description: $data['description'] ?? null,
            );

            return $this->success('تم تسجيل سداد السلفة', $transaction);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/employees/{employee}/salary-accrual
     */
    public function accrualSalary(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'amount'      => ['required', 'numeric', 'min:0.001'],
            'date'        => ['required', 'date'],
            'description' => ['nullable', 'string'],
        ]);

        try {
            $transaction = $this->employeeService->accrualSalary(
                employee: $employee,
                amount: $data['amount'],
                date: $data['date'],
                description: $data['description'] ?? null,
            );

            return $this->success('تم تسجيل استحقاق الراتب', $transaction, 201);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/employees/{employee}/salary-payment
     */
    public function paySalary(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'gross_amount'     => ['required', 'numeric', 'min:0.001'],
            'cash_account_id'  => ['required', 'integer', 'exists:accounts,id'],
            'date'             => ['required', 'date'],
            'advance_deduction' => ['nullable', 'numeric', 'min:0'],
            'description'      => ['nullable', 'string'],
        ]);

        try {
            $transaction = $this->employeeService->paySalary(
                employee: $employee,
                grossAmount: $data['gross_amount'],
                cashAccountId: $data['cash_account_id'],
                date: $data['date'],
                advanceDeduction: $data['advance_deduction'] ?? 0,
                description: $data['description'] ?? null,
            );

            $fresh = $employee->fresh();

            return $this->success('تم دفع الراتب بنجاح', [
                'transaction'        => $transaction,
                'outstanding_advance' => $fresh->outstanding_advance,
                'accrued_salary'     => $fresh->accrued_salary,
                'net_payable'        => $fresh->net_payable,
            ]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * GET /api/employees/{employee}/account-statement
     * كشف حساب الموظف — يستخدم ledger الموجود مسبقاً
     */
    public function accountStatement(Request $request, Employee $employee): JsonResponse
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to   = $request->input('to', now()->toDateString());
        $type = $request->input('type', 'all'); // advance | salary | all

        $accounts = [];

        if (in_array($type, ['advance', 'all']) && $employee->advance_account_id) {
            $accounts['advance'] = $this->getLedger($employee->advanceAccount, $from, $to);
        }

        if (in_array($type, ['salary', 'all']) && $employee->salary_account_id) {
            $accounts['salary'] = $this->getLedger($employee->salaryAccount, $from, $to);
        }

        return $this->success('كشف حساب الموظف', [
            'employee'          => ['id' => $employee->id, 'name' => $employee->name],
            'period'            => ['from' => $from, 'to' => $to],
            'outstanding_advance' => $employee->outstanding_advance,
            'accrued_salary'    => $employee->accrued_salary,
            'net_payable'       => $employee->net_payable,
            'accounts'          => $accounts,
        ]);
    }

    private function getLedger(\App\Models\Account $account, string $from, string $to): array
    {
        $entries = $account->entries()
            ->with(['transaction:id,transaction_number,date,type,description'])
            ->whereHas(
                'transaction',
                fn($q) =>
                $q->where('status', 'posted')->whereBetween('date', [$from, $to])
            )
            ->orderBy('created_at')
            ->get();

        $runningBalance = 0;
        $lines = $entries->map(function ($entry) use ($account, &$runningBalance) {
            $d = (float) $entry->debit;
            $c = (float) $entry->credit;

            $runningBalance += in_array($account->type, ['asset', 'expense'])
                ? ($d - $c) : ($c - $d);

            return [
                'date'               => $entry->transaction->date->format('Y-m-d'),
                'transaction_number' => $entry->transaction->transaction_number,
                'description'        => $entry->description ?? $entry->transaction->description,
                'debit'              => $d,
                'credit'             => $c,
                'balance'            => round($runningBalance, 3),
            ];
        });

        return [
            'account'         => ['code' => $account->code, 'name' => $account->name],
            'closing_balance' => round($runningBalance, 3),
            'lines'           => $lines,
        ];
    }
}
