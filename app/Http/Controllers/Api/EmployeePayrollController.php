<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\Employee;
use App\Models\EmployeePayroll;
use App\Services\Accounting\EmployeeAccountingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeePayrollController extends ApiController
{
    public function __construct(private readonly EmployeeAccountingService $accounting) {}

    public function index(Request $request, Employee $employee): JsonResponse
    {
        $filters = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ]);

        $rows = $employee->payrolls()
            ->with('cashAccount:id,code,name')
            ->when($filters['year'] ?? null, fn ($q, $year) => $q->where('period_year', $year))
            ->when($filters['month'] ?? null, fn ($q, $month) => $q->where('period_month', $month))
            ->orderByDesc('period_year')->orderByDesc('period_month')->get();

        return $this->success('Employee payroll history fetched', [
            'payrolls' => $rows,
            'total_net_paid' => round((float) $rows->where('status', 'paid')->sum('net_amount'), 2),
        ]);
    }

    public function process(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'period_month' => ['required', 'integer', 'between:1,12'],
            'period_year' => ['required', 'integer', 'between:2000,2100'],
            'cash_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'payment_date' => ['nullable', 'date'],
            'base_salary' => ['nullable', 'numeric', 'min:0'],
            'allowances' => ['nullable', 'numeric', 'min:0'],
            'deductions' => ['nullable', 'numeric', 'min:0'],
            'advance_deduction' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        [$calculatedBase, $workedHours, $workedDays] = $this->calculateBaseSalary(
            $employee,
            (int) $data['period_year'],
            (int) $data['period_month']
        );

        $base = array_key_exists('base_salary', $data) && $data['base_salary'] !== null
            ? (float) $data['base_salary']
            : $calculatedBase;
        $allowances = (float) ($data['allowances'] ?? 0);
        $deductions = (float) ($data['deductions'] ?? 0);
        $advanceDeduction = (float) ($data['advance_deduction'] ?? 0);
        $gross = round($base + $allowances, 2);
        $payable = round($gross - $deductions, 2);
        $net = round($payable - $advanceDeduction, 2);

        if ($gross <= 0) return $this->error('إجمالي الراتب يجب أن يكون أكبر من صفر', 422);
        if ($payable <= 0) return $this->error('الخصومات لا يمكن أن تساوي أو تتجاوز إجمالي الراتب', 422);
        if ($advanceDeduction > $payable) return $this->error('خصم السلفة أكبر من الراتب القابل للدفع', 422);

        $period = Carbon::create($data['period_year'], $data['period_month'], 1);
        $accrualDate = $period->copy()->endOfMonth()->toDateString();
        $paymentDate = $data['payment_date'] ?? $accrualDate;
        $description = $data['description'] ?? sprintf('راتب %02d/%d - %s', $data['period_month'], $data['period_year'], $employee->name);

        try {
            $payroll = DB::transaction(function () use (
                $employee, $data, $base, $allowances, $deductions, $advanceDeduction,
                $gross, $payable, $net, $workedHours, $workedDays, $accrualDate,
                $paymentDate, $description, $request
            ) {
                // قفل سجل الموظف يمنع عمليتي راتب متزامنتين لنفس الفترة.
                Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
                if ($employee->payrolls()
                    ->where('period_year', $data['period_year'])
                    ->where('period_month', $data['period_month'])
                    ->exists()) {
                    throw new \RuntimeException('تم إنشاء راتب لهذا الموظف في هذا الشهر مسبقاً');
                }

                $accrual = $this->accounting->accrualSalary(
                    employee: $employee,
                    amount: $payable,
                    date: $accrualDate,
                    description: "استحقاق {$description}",
                    branchId: $data['branch_id'] ?? $employee->branch_id,
                );

                $payment = $this->accounting->paySalary(
                    employee: $employee,
                    grossAmount: $payable,
                    cashAccountId: (int) $data['cash_account_id'],
                    date: $paymentDate,
                    advanceDeduction: $advanceDeduction,
                    description: "دفع {$description}",
                    branchId: $data['branch_id'] ?? $employee->branch_id,
                );

                return EmployeePayroll::create([
                    'employee_id' => $employee->id,
                    'period_month' => $data['period_month'],
                    'period_year' => $data['period_year'],
                    'salary_type' => $employee->salary_type ?? 'monthly',
                    'worked_hours' => $workedHours,
                    'worked_days' => $workedDays,
                    'base_salary' => $base,
                    'allowances' => $allowances,
                    'deductions' => $deductions,
                    'advance_deduction' => $advanceDeduction,
                    'gross_amount' => $gross,
                    'payable_amount' => $payable,
                    'net_amount' => max(0, $net),
                    'cash_account_id' => $data['cash_account_id'],
                    'payment_date' => $paymentDate,
                    'status' => 'paid',
                    'notes' => $data['description'] ?? null,
                    'accrual_transaction_id' => $accrual->id,
                    'payment_transaction_id' => $payment->id,
                    'created_by' => $request->user()?->id,
                ])->load('cashAccount:id,code,name');
            });

            return $this->success('تم احتساب وصرف الراتب بنجاح', $payroll, 201);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    private function calculateBaseSalary(Employee $employee, int $year, int $month): array
    {
        $from = Carbon::create($year, $month, 1)->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $attendance = $employee->attendances()->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->get();

        $workedMinutes = (int) $attendance->sum('worked_minutes');
        $workedHours = round($workedMinutes / 60, 2);
        $workedDays = $attendance->whereIn('status', ['PRESENT', 'LATE'])->count();
        $salaryType = $employee->salary_type ?? 'monthly';

        $base = match ($salaryType) {
            'hourly' => $workedHours * (float) ($employee->hourly_rate ?? 0),
            'daily' => $workedDays * (float) ($employee->daily_rate ?? 0),
            default => (float) ($employee->salary ?? 0),
        };

        return [round($base, 2), $workedHours, $workedDays];
    }
}
