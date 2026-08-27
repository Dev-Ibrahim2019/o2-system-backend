<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeLoan;
use App\Models\EmployeeWithdrawal;
use App\Models\Entry;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class EmployeeModuleService
{
    private ?int $advanceAccountId = null;
    private ?int $salaryAccountId = null;
    private ?int $loanAccountId = null;

    public function __construct(
        private readonly SubledgerService $subledgerService,
    ) {}

    /**
     * @param array<string,mixed> $filters
     */
    public function dashboard(array $filters): array
    {
        [$month, $year, $periodStart, $periodEnd] = $this->resolvePeriod($filters);
        $employees = $this->baseEmployeeQuery($filters)->get();
        $active = $employees->where('status', 'ACTIVE');
        $inactive = $employees->where('status', '!=', 'ACTIVE');

        $activeIds = $active->pluck('id');
        $advanceMap = $this->bulkAdvanceBalances($activeIds);
        $salaryMap  = $this->bulkSalaryBalances($activeIds);
        $loanMap    = $this->bulkLoanBalances($activeIds);

        $periodAdvancesIssued = $this->sumPeriodAdvancesIssued($activeIds, $periodStart, $periodEnd);
        $periodAdvancesRepaid = $this->sumPeriodAdvanceRepayments($activeIds, $periodStart, $periodEnd);
        $periodSalaryPaid     = $this->sumPeriodSalaryPayments($activeIds, $periodStart, $periodEnd);

        $totalOutstandingAdvances = array_sum($advanceMap);
        $totalAccruedSalaries     = array_sum($salaryMap);
        $totalNetPayable          = 0;
        $pendingSalaryCount       = 0;

        foreach ($active as $emp) {
            $net = max(0, ($salaryMap[$emp->id] ?? 0) - ($advanceMap[$emp->id] ?? 0));
            $totalNetPayable += $net;
            if ($net > 0) {
                $pendingSalaryCount++;
            }
        }

        // القرض الحقيقي يُقاس من الـ subledger، وليس من جدول employee_loans فقط،
        // لأن الإصدارات السابقة كانت تسجل القيد المحاسبي بدون إنشاء سجل القرض.
        $employeesWithLoans = collect($loanMap)->filter(fn ($balance) => (float) $balance > 0.001)->count();

        $attendanceRecords = EmployeeAttendance::query()
            ->whereIn('employee_id', $activeIds)
            ->whereBetween('work_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->whereNotIn('status', ['DAY_OFF', 'LEAVE'])
            ->get(['status']);
        $attendancePercentage = $attendanceRecords->count() > 0
            ? round(($attendanceRecords->whereIn('status', ['PRESENT', 'LATE'])->count() / $attendanceRecords->count()) * 100, 1)
            : null;

        $employeesWithoutSalary = $active->filter(fn ($e) => (float) ($e->salary ?? 0) <= 0)->count();
        $departmentsCount = $active->pluck('department_id')->filter()->unique()->count();

        $deptBreakdown = $active->groupBy(fn ($e) => $e->department?->name ?? 'بدون قسم')
            ->map(fn ($group, $dept) => [
                'department' => $dept,
                'count'      => $group->count(),
                'salary'     => round((float) $group->sum('salary'), 2),
                'advances'   => round($group->sum(fn ($e) => $advanceMap[$e->id] ?? 0), 2),
            ])->values();

        return [
            'period'                   => ['month' => $month, 'year' => $year, 'from' => $periodStart->toDateString(), 'to' => $periodEnd->toDateString()],
            'total_employees'          => $employees->count(),
            'active_employees'         => $active->count(),
            'inactive_employees'       => $inactive->count(),
            'total_salaries'           => round((float) $active->sum('salary'), 2),
            'monthly_salary_expense'   => round((float) $active->sum('salary'), 2),
            'current_month_salaries'   => round($periodSalaryPaid, 2),
            'outstanding_advances'     => round($totalOutstandingAdvances, 2),
            'paid_advances'            => round($periodAdvancesRepaid, 2),
            'advances_issued_period'   => round($periodAdvancesIssued, 2),
            'total_payments'           => round($totalNetPayable, 2),
            'average_salary'           => $active->count() > 0 ? round((float) $active->sum('salary') / $active->count(), 2) : 0,
            'departments_count'        => $departmentsCount,
            'attendance_percentage'    => $attendancePercentage,
            'employees_with_loans'     => $employeesWithLoans,
            'employees_without_salary' => $employeesWithoutSalary,
            'pending_salary_employees' => $pendingSalaryCount,
            'upcoming_payroll'         => round($totalNetPayable, 2),
            'accrued_salaries'         => round($totalAccruedSalaries, 2),
            'department_breakdown'     => $deptBreakdown,
        ];
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function analytics(array $filters): array
    {
        [$month, $year] = [$this->intFilter($filters, 'month', now()->month), $this->intFilter($filters, 'year', now()->year)];
        $employees = $this->baseEmployeeQuery($filters)->where('status', 'ACTIVE')->get();
        $employeeIds = $employees->pluck('id');
        $advanceMap = $this->bulkAdvanceBalances($employeeIds);
        $salaryMap  = $this->bulkSalaryBalances($employeeIds);

        $departmentPayroll = $employees->groupBy(fn ($e) => $e->department?->name ?? 'بدون قسم')
            ->map(fn ($group, $name) => [
                'name'     => $name,
                'salaries' => round((float) $group->sum('salary'), 2),
                'advances' => round($group->sum(fn ($e) => $advanceMap[$e->id] ?? 0), 2),
                'count'    => $group->count(),
            ])->sortByDesc('salaries')->values();

        $monthlyTrend = $this->buildMonthlyTrend($employeeIds, $year);
        $salaryDistribution = $this->buildSalaryDistribution($employees);
        $topDepartments = $departmentPayroll->take(5)->values();

        $totalSalaries = round((float) $employees->sum('salary'), 2);
        $totalAdvances = round(array_sum($advanceMap), 2);
        $totalPayments = round($employees->sum(fn ($e) => max(0, ($salaryMap[$e->id] ?? 0) - ($advanceMap[$e->id] ?? 0))), 2);

        return [
            'period'               => ['month' => $month, 'year' => $year],
            'department_payroll'   => $departmentPayroll,
            'salary_distribution'  => $salaryDistribution,
            'top_departments'      => $topDepartments,
            'monthly_trend'        => $monthlyTrend,
            'payroll_trend'        => collect($monthlyTrend)->pluck('salaries')->values(),
            'advance_trend'        => collect($monthlyTrend)->pluck('advances')->values(),
            'monthly_comparison'   => $monthlyTrend,
            'totals'               => [
                'total_salaries'  => $totalSalaries,
                'total_advances'  => $totalAdvances,
                'total_payments'  => $totalPayments,
                'average_salary'  => $employees->count() > 0 ? round($totalSalaries / $employees->count(), 2) : 0,
                'total_employees' => $employees->count(),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function financialBatch(array $filters): array
    {
        $employees = $this->baseEmployeeQuery($filters)
            ->with(['department:id,name', 'branch:id,name'])
            ->orderBy('name')
            ->get();

        $ids = $employees->pluck('id');
        $advanceMap = $this->bulkAdvanceBalances($ids);
        $salaryMap  = $this->bulkSalaryBalances($ids);
        $loanMap    = $this->bulkLoanBalances($ids);
        $lastTxnMap = $this->bulkLastTransactionDates($ids);

        $result = $employees->map(function ($emp) use ($advanceMap, $salaryMap, $loanMap, $lastTxnMap) {
            $advBalance = $advanceMap[$emp->id] ?? 0;
            $salBalance = $salaryMap[$emp->id] ?? 0;

            return [
                'id'                    => $emp->id,
                'name'                  => $emp->name,
                'employeeId'            => $emp->employeeId,
                'phone'                 => $emp->phone,
                'status'                => $emp->status,
                'salary'                => (float) ($emp->salary ?? 0),
                'salary_type'           => $emp->salary_type ?? 'monthly',
                'department'            => $emp->department?->name,
                'department_id'         => $emp->department_id,
                'branch'                => $emp->branch?->name,
                'branch_id'             => $emp->branch_id,
                'job_title'             => $emp->jobTitle?->name ?? $this->resolveJobTitle($emp->jobTitleId),
                'job_description'       => $emp->jobTitle?->description,
                'hireDate'              => $emp->hireDate?->toDateString(),
                'outstanding_advance'   => $advBalance,
                'outstanding_loan'      => $loanMap[$emp->id] ?? 0,
                'accrued_salary'        => $salBalance,
                'net_payable'           => round(max(0, $salBalance - $advBalance), 2),
                'last_transaction_date' => $lastTxnMap[$emp->id] ?? null,
            ];
        });

        return [
            'employees' => $result->values(),
            'totals'    => [
                'total_employees'            => $result->count(),
                'active_employees'           => $result->where('status', 'ACTIVE')->count(),
                'total_salaries'             => round($result->sum('salary'), 2),
                'total_outstanding_advances' => round($result->sum('outstanding_advance'), 2),
                'total_accrued_salaries'     => round($result->sum('accrued_salary'), 2),
                'total_net_payable'          => round($result->sum('net_payable'), 2),
                'average_salary'             => $result->count() > 0 ? round($result->sum('salary') / $result->count(), 2) : 0,
            ],
        ];
    }

    public function employeeSummary(Employee $employee): array
    {
        $employee->load(['branch:id,name', 'department:id,name', 'jobTitle:id,name,description,is_active']);

        $balances = $this->subledgerService->getEmployeeBalances($employee->id);
        $manager  = $employee->managerId
            ? Employee::withoutGlobalScopes()->find($employee->managerId)
            : null;

        $lastSalaryPayment = Transaction::query()
            ->where('type', 'salary')
            ->where('status', 'posted')
            ->whereHas('entries', fn ($q) => $q->where('subledger_type', 'employee')->where('subledger_id', $employee->id))
            ->orderByDesc('date')
            ->first();

        $lastAdvance = Transaction::query()
            ->where('status', 'posted')
            ->where(function ($q) {
                $q->where('type', 'payment')->where('transaction_number', 'like', 'ADV%');
            })
            ->whereHas('entries', fn ($q) => $q->where('subledger_type', 'employee')->where('subledger_id', $employee->id)->where('debit', '>', 0))
            ->orderByDesc('date')
            ->first();

        $lastWithdrawal = EmployeeWithdrawal::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'posted')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();

        $totalWithdrawals = (float) EmployeeWithdrawal::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'posted')
            ->sum('amount');

        $salesAgg = Order::query()
            ->where('cashier_id', $employee->id)
            ->whereIn('status', ['paid', 'completed', 'served'])
            ->selectRaw('COALESCE(SUM(total),0) as total_sales, COUNT(*) as sales_count, MAX(created_at) as last_sale_at')
            ->first();

        $lastAdvanceAmount = $lastAdvance
            ? (float) $lastAdvance->entries()->where('subledger_type', 'employee')->where('subledger_id', $employee->id)->sum('debit')
            : 0;

        return [
            'employee' => [
                'id'           => $employee->id,
                'name'         => $employee->name,
                'employeeId'   => $employee->employeeId,
                'status'       => $employee->status,
                'hireDate'     => $employee->hireDate?->toDateString(),
                'salary'       => (float) ($employee->salary ?? 0),
                'salary_type'  => $employee->salary_type ?? 'monthly',
                'job_title'    => $employee->jobTitle?->name ?? $this->resolveJobTitle($employee->jobTitleId),
                'job_description' => $employee->jobTitle?->description,
                'department'   => $employee->department?->name,
                'branch'       => $employee->branch?->name,
                'manager'      => $manager ? ['id' => $manager->id, 'name' => $manager->name] : null,
            ],
            'financial' => [
                'current_salary'           => (float) ($employee->salary ?? 0),
                'last_salary_payment'      => [
                    'date'   => $lastSalaryPayment?->date?->toDateString(),
                    'amount' => $lastSalaryPayment
                        ? (float) $lastSalaryPayment->entries()->where('subledger_type', 'employee')->where('subledger_id', $employee->id)->sum('debit')
                        : 0,
                    'number' => $lastSalaryPayment?->transaction_number,
                ],
                'outstanding_advance'      => $balances['outstanding_advance'],
                'outstanding_loan'         => $balances['outstanding_loan'] ?? 0,
                'accrued_salary'           => $balances['accrued_salary'],
                'net_payable'              => max(0, $balances['accrued_salary'] - $balances['outstanding_advance']),
                'current_balance'          => max(0, $balances['accrued_salary'] - $balances['outstanding_advance']),
                'total_sales_withdrawals'  => round((float) ($salesAgg->total_sales ?? 0), 2),
                'sales_count'              => (int) ($salesAgg->sales_count ?? 0),
                'total_withdrawals'        => round($totalWithdrawals, 2),
                'last_withdrawal'          => [
                    'date'   => $lastWithdrawal?->date?->toDateString(),
                    'amount' => round((float) ($lastWithdrawal?->amount ?? 0), 2),
                    'number' => $lastWithdrawal?->transaction_id,
                ],
                'last_advance'             => [
                    'date'   => $lastAdvance?->date?->toDateString(),
                    'amount' => round($lastAdvanceAmount, 2),
                    'number' => $lastAdvance?->transaction_number,
                ],
                'last_sale_at'             => $salesAgg?->last_sale_at ? Carbon::parse($salesAgg->last_sale_at)->toDateString() : null,
                'total_purchases'          => 0,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function baseEmployeeQuery(array $filters)
    {
        $query = Employee::query()->with(['department:id,name', 'branch:id,name', 'jobTitle:id,name,description,is_active']);

        if (!empty($filters['department_id'])) {
            $query->where('department_id', (int) $filters['department_id']);
        }
        if (!empty($filters['branch_id'])) {
            $query->where('branch_id', (int) $filters['branch_id']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', strtoupper((string) $filters['status']));
        }
        if (!empty($filters['employment_type']) || !empty($filters['type_id'])) {
            $query->where('typeId', $filters['employment_type'] ?? $filters['type_id']);
        }
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('employeeId', 'like', "%{$search}%"));
        }
        if (!empty($filters['salary_from'])) {
            $query->where('salary', '>=', (float) $filters['salary_from']);
        }
        if (!empty($filters['salary_to'])) {
            $query->where('salary', '<=', (float) $filters['salary_to']);
        }
        if (!empty($filters['hire_date_from'])) {
            $query->whereDate('hireDate', '>=', $filters['hire_date_from']);
        }
        if (!empty($filters['hire_date_to'])) {
            $query->whereDate('hireDate', '<=', $filters['hire_date_to']);
        }
        if (!empty($filters['from']) || !empty($filters['to'])) {
            $from = !empty($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : null;
            $to   = !empty($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : null;
            $activityIds = $this->employeeIdsWithActivityBetween($from, $to);
            $query->whereIn('id', $activityIds ?: [-1]);
        }

        return $query;
    }

    /**
     * @return array{0:int,1:int,2:Carbon,3:Carbon}
     */
    private function resolvePeriod(array $filters): array
    {
        $month = $this->intFilter($filters, 'month', now()->month);
        $year  = $this->intFilter($filters, 'year', now()->year);
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end   = (clone $start)->endOfMonth();

        return [$month, $year, $start, $end];
    }

    private function intFilter(array $filters, string $key, int $default): int
    {
        return isset($filters[$key]) ? (int) $filters[$key] : $default;
    }

    /**
     * @param Collection<int,int>|array<int,int> $employeeIds
     * @return array<int,float>
     */
    private function bulkAdvanceBalances($employeeIds): array
    {
        if (empty($employeeIds) || !($employeeIds instanceof Collection ? $employeeIds->isNotEmpty() : count($employeeIds))) {
            return [];
        }

        $account = $this->advanceAccount();
        if (!$account) {
            return [];
        }

        $isDebitNormal = in_array($account->type, ['asset', 'expense'], true);
        $rows = Entry::query()
            ->selectRaw('subledger_id, COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->where('subledger_type', 'employee')
            ->whereIn('subledger_id', $employeeIds)
            ->where('account_id', $account->id)
            ->whereHas('transaction', fn ($q) => $q->where('status', 'posted'))
            ->groupBy('subledger_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->subledger_id] = round($isDebitNormal ? ((float) $row->d - (float) $row->c) : ((float) $row->c - (float) $row->d), 2);
        }

        return $map;
    }

    /** @return array<int,float> */
    private function bulkSalaryBalances($employeeIds): array
    {
        if (empty($employeeIds)) {
            return [];
        }

        $account = $this->salaryAccount();
        if (!$account) {
            return [];
        }

        $isDebitNormal = in_array($account->type, ['asset', 'expense'], true);
        $rows = Entry::query()
            ->selectRaw('subledger_id, COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->where('subledger_type', 'employee')
            ->whereIn('subledger_id', $employeeIds)
            ->where('account_id', $account->id)
            ->whereHas('transaction', fn ($q) => $q->where('status', 'posted'))
            ->groupBy('subledger_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->subledger_id] = round($isDebitNormal ? ((float) $row->d - (float) $row->c) : ((float) $row->c - (float) $row->d), 2);
        }

        return $map;
    }

    /** @return array<int,float> */
    private function bulkLoanBalances($employeeIds): array
    {
        if (empty($employeeIds)) {
            return [];
        }

        $account = $this->loanAccount();
        if (!$account) {
            return [];
        }

        $isDebitNormal = in_array($account->type, ['asset', 'expense'], true);
        $rows = Entry::query()
            ->selectRaw('subledger_id, COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->where('subledger_type', 'employee')
            ->whereIn('subledger_id', $employeeIds)
            ->where('account_id', $account->id)
            ->whereHas('transaction', fn ($q) => $q->where('status', 'posted'))
            ->groupBy('subledger_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->subledger_id] = round($isDebitNormal ? ((float) $row->d - (float) $row->c) : ((float) $row->c - (float) $row->d), 2);
        }

        return $map;
    }

    /** @return array<int,string> */
    private function bulkLastTransactionDates($employeeIds): array
    {
        if (empty($employeeIds)) {
            return [];
        }

        $rows = Entry::query()
            ->selectRaw('entries.subledger_id, MAX(transactions.date) as last_date')
            ->join('transactions', 'transactions.id', '=', 'entries.transaction_id')
            ->where('entries.subledger_type', 'employee')
            ->whereIn('entries.subledger_id', $employeeIds)
            ->where('transactions.status', 'posted')
            ->groupBy('entries.subledger_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->subledger_id] = Carbon::parse($row->last_date)->toDateString();
        }

        return $map;
    }

    private function sumPeriodAdvancesIssued($employeeIds, Carbon $from, Carbon $to): float
    {
        if (empty($employeeIds)) {
            return 0;
        }

        $accountId = $this->advanceAccount()?->id;
        if (!$accountId) {
            return 0;
        }

        return (float) Entry::query()
            ->where('subledger_type', 'employee')
            ->whereIn('subledger_id', $employeeIds)
            ->where('account_id', $accountId)
            ->where('debit', '>', 0)
            ->whereHas('transaction', fn ($q) => $q
                ->where('status', 'posted')
                ->whereBetween('date', [$from, $to])
                ->where('transaction_number', 'like', 'ADV%'))
            ->sum('debit');
    }

    private function sumPeriodAdvanceRepayments($employeeIds, Carbon $from, Carbon $to): float
    {
        if (empty($employeeIds)) {
            return 0;
        }

        $accountId = $this->advanceAccount()?->id;
        if (!$accountId) {
            return 0;
        }

        return (float) Entry::query()
            ->where('subledger_type', 'employee')
            ->whereIn('subledger_id', $employeeIds)
            ->where('account_id', $accountId)
            ->where('credit', '>', 0)
            ->whereHas('transaction', fn ($q) => $q
                ->where('status', 'posted')
                ->whereBetween('date', [$from, $to])
                ->where('transaction_number', 'like', 'REPR%'))
            ->sum('credit');
    }

    private function sumPeriodSalaryPayments($employeeIds, Carbon $from, Carbon $to): float
    {
        if (empty($employeeIds)) {
            return 0;
        }

        $accountId = $this->salaryAccount()?->id;
        if (!$accountId) {
            return 0;
        }

        return (float) Entry::query()
            ->where('subledger_type', 'employee')
            ->whereIn('subledger_id', $employeeIds)
            ->where('account_id', $accountId)
            ->where('debit', '>', 0)
            ->whereHas('transaction', fn ($q) => $q
                ->where('status', 'posted')
                ->where('type', 'salary')
                ->whereBetween('date', [$from, $to]))
            ->sum('debit');
    }

    /** @return array<int,int> */
    private function employeeIdsWithActivityBetween(?Carbon $from, ?Carbon $to): array
    {
        $query = Entry::query()
            ->select('subledger_id')
            ->where('subledger_type', 'employee')
            ->whereHas('transaction', function ($q) use ($from, $to) {
                $q->where('status', 'posted');
                if ($from) {
                    $q->where('date', '>=', $from);
                }
                if ($to) {
                    $q->where('date', '<=', $to);
                }
            })
            ->distinct();

        return $query->pluck('subledger_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<int,array{label:string,count:int,total:float}> */
    private function buildSalaryDistribution(Collection $employees): array
    {
        $buckets = [
            ['label' => '0 - 2,000', 'min' => 0, 'max' => 2000],
            ['label' => '2,001 - 4,000', 'min' => 2001, 'max' => 4000],
            ['label' => '4,001 - 6,000', 'min' => 4001, 'max' => 6000],
            ['label' => '6,001+', 'min' => 6001, 'max' => PHP_FLOAT_MAX],
        ];

        $result = [];
        foreach ($buckets as $bucket) {
            $group = $employees->filter(fn ($e) => (float) $e->salary >= $bucket['min'] && (float) $e->salary <= $bucket['max']);
            $result[] = [
                'label' => $bucket['label'],
                'count' => $group->count(),
                'total' => round((float) $group->sum('salary'), 2),
            ];
        }

        return $result;
    }

    /** @return array<int,array{month:string,salaries:float,advances:float,payments:float}> */
    private function buildMonthlyTrend($employeeIds, int $year): array
    {
        $trend = [];
        for ($m = 1; $m <= 12; $m++) {
            $start = Carbon::create($year, $m, 1)->startOfDay();
            $end   = (clone $start)->endOfMonth();
            $trend[] = [
                'month'    => $start->format('Y-m'),
                'label'    => $start->translatedFormat('M'),
                'salaries' => round($this->sumPeriodSalaryPayments($employeeIds, $start, $end), 2),
                'advances' => round($this->sumPeriodAdvancesIssued($employeeIds, $start, $end), 2),
                'payments' => round($this->sumPeriodAdvanceRepayments($employeeIds, $start, $end), 2),
            ];
        }

        return $trend;
    }

    private function resolveJobTitle(?string $jobTitleId): ?string
    {
        if (!$jobTitleId) {
            return null;
        }

        static $cache = [];
        if (!isset($cache[$jobTitleId])) {
            $cache[$jobTitleId] = JobTitle::query()->where('id', $jobTitleId)->value('name');
        }

        return $cache[$jobTitleId];
    }

    private function advanceAccount(): ?Account
    {
        if ($this->advanceAccountId) {
            return Account::find($this->advanceAccountId);
        }
        $account = Account::where('code', '1130')->first();
        $this->advanceAccountId = $account?->id;

        return $account;
    }

    private function salaryAccount(): ?Account
    {
        if ($this->salaryAccountId) {
            return Account::find($this->salaryAccountId);
        }
        $account = Account::where('code', '2120')->first();
        $this->salaryAccountId = $account?->id;

        return $account;
    }

    private function loanAccount(): ?Account
    {
        if ($this->loanAccountId) {
            return Account::find($this->loanAccountId);
        }
        $account = Account::where('code', '2130')->first();
        $this->loanAccountId = $account?->id;

        return $account;
    }
}
