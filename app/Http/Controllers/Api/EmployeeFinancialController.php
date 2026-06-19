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

    // ──────────────────────────────────────────────────────────
    // 7. Batch employee financial data (for directory)
    // ──────────────────────────────────────────────────────────

    /**
     * GET /api/employees/financial-batch
     * جلب البيانات المالية لجميع الموظفين دفعة واحدة
     */
    public function financialBatch(Request $request): JsonResponse
    {
        $employees = \App\Models\Employee::with(['department', 'branch'])->get();

        $result = [];
        foreach ($employees as $emp) {
            $advBalance = $this->subledgerService->getBalance('employee', $emp->id, '1130');
            $salBalance = $this->subledgerService->getBalance('employee', $emp->id, '2120');
            $netPayable = max(0, $salBalance - $advBalance);

            // Get last transaction date
            $lastTransaction = \App\Models\Entry::forSubledger('employee', $emp->id)
                ->whereHas('transaction', fn($q) => $q->where('status', 'posted'))
                ->orderByDesc('id')
                ->first();

            $result[] = [
                'id'                   => $emp->id,
                'name'                 => $emp->name,
                'employeeId'           => $emp->employeeId,
                'phone'                => $emp->phone,
                'status'               => $emp->status,
                'salary'               => (float) ($emp->salary ?? 0),
                'department'           => $emp->department?->name,
                'department_id'        => $emp->department_id,
                'branch'               => $emp->branch?->name,
                'branch_id'            => $emp->branch_id,
                'job_title'            => null,
                'hireDate'             => $emp->hireDate?->toDateString(),
                'outstanding_advance'  => round($advBalance, 2),
                'accrued_salary'       => round($salBalance, 2),
                'net_payable'          => round($netPayable, 2),
                'last_transaction_date' => $lastTransaction?->transaction?->date?->toDateString(),
            ];
        }

        return $this->success('البيانات المالية للموظفين', [
            'employees' => $result,
            'totals' => [
                'total_employees'       => $employees->count(),
                'active_employees'      => $employees->where('status', 'active')->count(),
                'total_salaries'        => round($employees->sum('salary'), 2),
                'total_outstanding_advances' => round(collect($result)->sum('outstanding_advance'), 2),
                'total_accrued_salaries'     => round(collect($result)->sum('accrued_salary'), 2),
                'total_net_payable'          => round(collect($result)->sum('net_payable'), 2),
                'average_salary'        => $employees->where('status', 'active')->count() > 0
                    ? round($employees->where('status', 'active')->sum('salary') / $employees->where('status', 'active')->count(), 2)
                    : 0,
            ],
        ]);
    }

    // ──────────────────────────────────────────────────────────
    // 8. تسوية مالية (Settlement)
    // ──────────────────────────────────────────────────────────

    /**
     * POST /api/employees/{employee}/settlement
     */
    public function recordSettlement(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'amount'          => ['required', 'numeric', 'min:0.001'],
            'cash_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'date'            => ['required', 'date'],
            'type'            => ['required', 'string', 'in:debit,credit'],
            'description'     => ['nullable', 'string', 'max:500'],
            'branch_id'       => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        try {
            $transaction = $this->employeeService->recordSettlement(
                employee: $employee,
                amount: $data['amount'],
                cashAccountId: $data['cash_account_id'],
                date: $data['date'],
                type: $data['type'],
                description: $data['description'] ?? null,
                branchId: $data['branch_id'] ?? null,
            );

            return $this->success('تم تسجيل التسوية بنجاح', [
                'transaction' => $transaction,
            ], 201);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ──────────────────────────────────────────────────────────
    // 9. Dashboard / إحصائيات عامة
    // ──────────────────────────────────────────────────────────

    /**
     * GET /api/employees/dashboard
     * لوحة الموظفين — إحصائيات سريعة
     */
    public function dashboard(Request $request): JsonResponse
    {
        $month = (int) $request->input('month', now()->month);
        $year  = (int) $request->input('year', now()->year);
        $departmentId = $request->input('department_id');
        $branchId     = $request->input('branch_id');

        $employees = \App\Models\Employee::query()
            ->when($departmentId, fn($q) => $q->where('department_id', $departmentId))
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->get();

        $active = $employees->where('status', 'active');

        $totalSalaries = $active->sum('salary');
        $totalAdvances = 0;
        $totalPayments = 0;
        $pendingSalaryCount = 0;

        foreach ($active as $emp) {
            $adv = $this->subledgerService->getBalance('employee', $emp->id, '1130');
            $sal = $this->subledgerService->getBalance('employee', $emp->id, '2120');
            $totalAdvances += $adv;
            $netPayable = max(0, $sal - $adv);
            $totalPayments += $netPayable;
            if ($netPayable > 0) $pendingSalaryCount++;
        }

        $totalEmployees = $employees->count();
        $activeCount = $active->count();
        $avgSalary = $activeCount > 0 ? $totalSalaries / $activeCount : 0;

        // Department breakdown
        $deptBreakdown = $active->groupBy(fn($e) => $e->department?->name ?? 'بدون قسم')
            ->map(function ($group, $dept) {
                return [
                    'department' => $dept,
                    'count'      => $group->count(),
                    'salary'     => $group->sum('salary'),
                    'advances'   => $group->sum(fn($e) => $this->subledgerService->getBalance('employee', $e->id, '1130')),
                ];
            })->values();

        return $this->success('إحصائيات لوحة الموظفين', [
            'total_employees'        => $totalEmployees,
            'active_employees'       => $activeCount,
            'monthly_salary_expense' => $totalSalaries,
            'outstanding_advances'   => $totalAdvances,
            'total_payments'         => $totalPayments,
            'average_salary'         => round($avgSalary, 2),
            'pending_salary_employees' => $pendingSalaryCount,
            'department_breakdown'   => $deptBreakdown,
        ]);
    }

    // ──────────────────────────────────────────────────────────
    // 10. Analytics / تحليلات
    // ──────────────────────────────────────────────────────────

    /**
     * GET /api/employees/analytics
     * تحليلات الموظفين — حسب القسم
     */
    public function analytics(Request $request): JsonResponse
    {
        $month = (int) $request->input('month', now()->month);
        $year  = (int) $request->input('year', now()->year);
        $departmentId = $request->input('department_id');

        $employees = \App\Models\Employee::query()
            ->when($departmentId, fn($q) => $q->where('department_id', $departmentId))
            ->where('status', 'active')
            ->get();

        $deptPayroll = $employees->groupBy(fn($e) => $e->department?->name ?? 'بدون قسم')
            ->map(function ($group, $name) {
                $advances = $group->sum(fn($e) => $this->subledgerService->getBalance('employee', $e->id, '1130'));
                return [
                    'name'     => $name,
                    'salaries' => $group->sum('salary'),
                    'advances' => $advances,
                    'count'    => $group->count(),
                ];
            })->values();

        $totalSalaries = $employees->sum('salary');
        $totalEmployees = $employees->count();
        $totalAdvances = $employees->sum(fn($e) => $this->subledgerService->getBalance('employee', $e->id, '1130'));
        $totalPayments = $employees->sum(fn($e) => max(0, $this->subledgerService->getBalance('employee', $e->id, '2120') - $this->subledgerService->getBalance('employee', $e->id, '1130')));

        return $this->success('تحليلات الموظفين', [
            'department_payroll' => $deptPayroll,
            'totals' => [
                'total_salaries'  => $totalSalaries,
                'total_advances'  => $totalAdvances,
                'total_payments'  => $totalPayments,
                'average_salary'  => $totalEmployees > 0 ? round($totalSalaries / $totalEmployees, 2) : 0,
                'total_employees' => $totalEmployees,
            ],
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
