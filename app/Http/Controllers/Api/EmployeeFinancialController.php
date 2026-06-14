<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\Employee;
use App\Services\Accounting\EmployeeAccountingService;
use App\Services\Accounting\SubledgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeFinancialController extends ApiController
{
    public function __construct(
        private readonly EmployeeAccountingService $employeeService,
        private readonly SubledgerService $subledgerService,
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
            'branch_id'       => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        try {
            $transaction = $this->employeeService->recordAdvance(
                employee: $employee,
                amount: $data['amount'],
                cashAccountId: $data['cash_account_id'],
                date: $data['date'],
                description: $data['description'] ?? null,
                branchId: $data['branch_id'] ?? $request->user()?->branch_id,
            );

            $balances = $this->subledgerService->getEmployeeBalances($employee->id);

            return $this->success('تم تسجيل السلفة بنجاح', [
                'transaction'         => $transaction,
                'outstanding_advance' => $balances['outstanding_advance'],
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
            'branch_id'       => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        try {
            $transaction = $this->employeeService->recordAdvanceRepayment(
                employee: $employee,
                amount: $data['amount'],
                cashAccountId: $data['cash_account_id'],
                date: $data['date'],
                description: $data['description'] ?? null,
                branchId: $data['branch_id'] ?? null,
            );

            $balances = $this->subledgerService->getEmployeeBalances($employee->id);

            return $this->success('تم تسجيل سداد السلفة', [
                'transaction'         => $transaction,
                'outstanding_advance' => $balances['outstanding_advance'],
            ]);
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
            'branch_id'   => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        try {
            $transaction = $this->employeeService->accrualSalary(
                employee: $employee,
                amount: $data['amount'],
                date: $data['date'],
                description: $data['description'] ?? null,
                branchId: $data['branch_id'] ?? null,
            );

            $balances = $this->subledgerService->getEmployeeBalances($employee->id);

            return $this->success('تم تسجيل استحقاق الراتب', [
                'transaction'    => $transaction,
                'accrued_salary' => $balances['accrued_salary'],
            ], 201);
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
            'gross_amount'      => ['required', 'numeric', 'min:0.001'],
            'cash_account_id'   => ['required', 'integer', 'exists:accounts,id'],
            'date'              => ['required', 'date'],
            'advance_deduction' => ['nullable', 'numeric', 'min:0'],
            'description'       => ['nullable', 'string'],
            'branch_id'         => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        try {
            $transaction = $this->employeeService->paySalary(
                employee: $employee,
                grossAmount: $data['gross_amount'],
                cashAccountId: $data['cash_account_id'],
                date: $data['date'],
                advanceDeduction: $data['advance_deduction'] ?? 0,
                description: $data['description'] ?? null,
                branchId: $data['branch_id'] ?? null,
            );

            $balances = $this->subledgerService->getEmployeeBalances($employee->id);

            return $this->success('تم دفع الراتب بنجاح', [
                'transaction'         => $transaction,
                'outstanding_advance' => $balances['outstanding_advance'],
                'accrued_salary'      => $balances['accrued_salary'],
                'net_payable'         => max(0, $balances['accrued_salary'] - $balances['outstanding_advance']),
            ]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/employees/{employee}/loan
     */
    public function recordLoan(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'amount'          => ['required', 'numeric', 'min:0.001'],
            'cash_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'date'            => ['required', 'date'],
            'description'     => ['nullable', 'string', 'max:500'],
            'branch_id'       => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        try {
            $transaction = $this->employeeService->recordLoan(
                employee: $employee,
                amount: $data['amount'],
                cashAccountId: $data['cash_account_id'],
                date: $data['date'],
                description: $data['description'] ?? null,
                branchId: $data['branch_id'] ?? $request->user()?->branch_id,
            );

            $balances = $this->subledgerService->getEmployeeBalances($employee->id);

            return $this->success('تم تسجيل القرض بنجاح', [
                'transaction'            => $transaction,
                'outstanding_loan'       => $this->subledgerService->getBalance('employee', $employee->id, '2130'),
            ], 201);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/employees/{employee}/loan-repayment
     */
    public function recordLoanRepayment(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'amount'          => ['required', 'numeric', 'min:0.001'],
            'cash_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'date'            => ['required', 'date'],
            'description'     => ['nullable', 'string'],
            'branch_id'       => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        try {
            $transaction = $this->employeeService->recordLoanRepayment(
                employee: $employee,
                amount: $data['amount'],
                cashAccountId: $data['cash_account_id'],
                date: $data['date'],
                description: $data['description'] ?? null,
                branchId: $data['branch_id'] ?? null,
            );

            $balances = $this->subledgerService->getEmployeeBalances($employee->id);

            return $this->success('تم تسجيل سداد القرض', [
                'transaction'            => $transaction,
                'outstanding_loan'       => $this->subledgerService->getBalance('employee', $employee->id, '2130'),
            ]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * GET /api/employees/{employee}/loans
     */
    public function getLoans(Request $request, Employee $employee): JsonResponse
    {
        $loans = $employee->loans()->orderByDesc('date_granted')->get();

        return $this->success('تم جلب القروض', [
            'loans' => $loans,
        ]);
    }

    /**
     * GET /api/employees/{employee}/account-statement
     * كشف حساب الموظف (سلف + رواتب + قروض)
     */
    public function accountStatement(Request $request, Employee $employee): JsonResponse
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to   = $request->input('to',   now()->toDateString());
        $type = $request->input('type', 'all'); // advance | salary | loan | all

        $result = [];

        if (in_array($type, ['advance', 'all'])) {
            $result['advance'] = $this->subledgerService->getStatement(
                'employee',
                $employee->id,
                '1130',
                $from,
                $to
            );
        }

        if (in_array($type, ['salary', 'all'])) {
            $result['salary'] = $this->subledgerService->getStatement(
                'employee',
                $employee->id,
                '2120',
                $from,
                $to
            );
        }

        if (in_array($type, ['loan', 'all'])) {
            $result['loan'] = $this->subledgerService->getStatement(
                'employee',
                $employee->id,
                '2130',
                $from,
                $to
            );
        }

        $balances = $this->subledgerService->getEmployeeBalances($employee->id, $to);

        return $this->success('كشف حساب الموظف', [
            'employee'            => ['id' => $employee->id, 'name' => $employee->name],
            'period'              => ['from' => $from, 'to' => $to],
            'outstanding_advance' => $balances['outstanding_advance'],
            'outstanding_loan'    => $balances['outstanding_loan'] ?? 0,
            'accrued_salary'      => $balances['accrued_salary'],
            'net_payable'         => max(0, $balances['accrued_salary'] - $balances['outstanding_advance']),
            'accounts'            => $result,
        ]);
    }
}
