<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\Transaction;
use App\Models\Entry;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeFinanceController extends Controller
{
    public function grantAdvance(Request $request, Employee $employee)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'date_granted' => 'required|date',
            'cash_bank_account_id' => 'required|exists:accounts,id',
            'notes' => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            // Create the employee loan
            $loan = EmployeeLoan::create([
                'employee_id' => $employee->id,
                'amount' => $request->amount,
                'date_granted' => $request->date_granted,
                'notes' => $request->notes,
                'status' => 'pending',
            ]);

            // Create a new transaction for the advance
            $transaction = Transaction::create([
                'date' => $request->date_granted,
                'reference' => 'ADV-' . $employee->id . '-' . now()->format('YmdHis'),
                'type' => 'journal',
                'status' => 'posted',
                'description' => 'سلفة للموظف: ' . $employee->name,
                'source_type' => EmployeeLoan::class,
                'source_id' => $loan->id,
            ]);

            // Link the loan to the transaction
            $loan->transaction_id = $transaction->id;
            $loan->save();

            // Find the Employee Advances Account (assuming ID 1001 for example, this should be configurable)
            $employeeAdvancesAccount = Account::where('code', 'LIKE', '13%')->first(); // Example: Assets -> Current Assets -> Employee Advances
            if (!$employeeAdvancesAccount) {
                throw new \Exception('Employee Advances Account not found. Please configure it.');
            }

            // Debit: Employee Advances Account
            Entry::create([
                'transaction_id' => $transaction->id,
                'account_id' => $employeeAdvancesAccount->id,
                'debit' => $request->amount,
                'credit' => 0,
                'description' => 'سلفة للموظف: ' . $employee->name,
            ]);

            // Credit: Cash or Bank Account
            Entry::create([
                'transaction_id' => $transaction->id,
                'account_id' => $request->cash_bank_account_id,
                'debit' => 0,
                'credit' => $request->amount,
                'description' => 'سلفة للموظف: ' . $employee->name,
            ]);

            DB::commit();

            return response()->json(['message' => 'تم منح السلفة بنجاح.', 'loan' => $loan->load('transaction')], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'فشل منح السلفة.', 'error' => $e->getMessage()], 500);
        }
    }

    public function repayAdvance(Request $request, Employee $employee, EmployeeLoan $loan)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'repayment_date' => 'required|date',
            'cash_bank_account_id' => 'required|exists:accounts,id',
            'notes' => 'nullable|string',
        ]);

        if ($loan->employee_id !== $employee->id) {
            throw ValidationException::withMessages([
                'loan' => 'السلفة لا تنتمي لهذا الموظف.',
            ]);
        }

        DB::beginTransaction();

        try {
            $remainingAmount = $loan->amount - $loan->amount_paid;
            if ($request->amount > $remainingAmount) {
                throw ValidationException::withMessages([
                    'amount' => 'مبلغ السداد أكبر من المبلغ المتبقي للسلفة.',
                ]);
            }

            $loan->amount_paid += $request->amount;
            if ($loan->amount_paid >= $loan->amount) {
                $loan->status = 'repaid';
            } else {
                $loan->status = 'partially_repaid';
            }
            $loan->save();

            // Create a new transaction for the repayment
            $transaction = Transaction::create([
                'date' => $request->repayment_date,
                'reference' => 'REPAY-' . $loan->id . '-' . now()->format('YmdHis'),
                'type' => 'journal',
                'status' => 'posted',
                'description' => 'سداد سلفة للموظف: ' . $employee->name . ' (سلفة رقم ' . $loan->id . ')',
                'source_type' => EmployeeLoan::class,
                'source_id' => $loan->id,
            ]);

            // Find the Employee Advances Account
            $employeeAdvancesAccount = Account::where('code', 'LIKE', '13%')->first(); // Example: Assets -> Current Assets -> Employee Advances
            if (!$employeeAdvancesAccount) {
                throw new \Exception('Employee Advances Account not found. Please configure it.');
            }

            // Debit: Cash or Bank Account
            Entry::create([
                'transaction_id' => $transaction->id,
                'account_id' => $request->cash_bank_account_id,
                'debit' => $request->amount,
                'credit' => 0,
                'description' => 'سداد سلفة للموظف: ' . $employee->name,
            ]);

            // Credit: Employee Advances Account
            Entry::create([
                'transaction_id' => $transaction->id,
                'account_id' => $employeeAdvancesAccount->id,
                'debit' => 0,
                'credit' => $request->amount,
                'description' => 'سداد سلفة للموظف: ' . $employee->name,
            ]);

            DB::commit();

            return response()->json(['message' => 'تم سداد السلفة بنجاح.', 'loan' => $loan->load('transaction')], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'فشل سداد السلفة.', 'error' => $e->getMessage()], 500);
        }
    }

    public function paySalary(Request $request, Employee $employee)
    {
        $request->validate([
            'gross_salary' => 'required|numeric|min:0',
            'payment_date' => 'required|date',
            'cash_bank_account_id' => 'required|exists:accounts,id',
            'notes' => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $grossSalary = $request->gross_salary;
            $deductedLoansAmount = 0;

            // Get all pending loans for the employee
            $pendingLoans = $employee->loans()->where('status', 'pending')->orWhere('status', 'partially_repaid')->get();

            foreach ($pendingLoans as $loan) {
                $remainingAmount = $loan->amount - $loan->amount_paid;
                if ($remainingAmount > 0) {
                    // Deduct from salary until loan is fully repaid or salary is exhausted
                    $deduction = min($remainingAmount, $grossSalary - $deductedLoansAmount);
                    if ($deduction <= 0) break; // No more salary to deduct

                    $loan->amount_paid += $deduction;
                    $deductedLoansAmount += $deduction;

                    if ($loan->amount_paid >= $loan->amount) {
                        $loan->status = 'repaid';
                    } else {
                        $loan->status = 'partially_repaid';
                    }
                    $loan->save();
                }
            }

            $netSalary = $grossSalary - $deductedLoansAmount;

            // Create a new transaction for salary payment
            $transaction = Transaction::create([
                'date' => $request->payment_date,
                'reference' => 'SAL-' . $employee->id . '-' . now()->format('YmdHis'),
                'type' => 'salary',
                'status' => 'posted',
                'description' => 'دفع راتب الموظف: ' . $employee->name,
                'source_type' => Employee::class,
                'source_id' => $employee->id,
            ]);

            // Find relevant accounts
            $salaryExpenseAccount = Account::where('code', 'LIKE', '61%')->first(); // Example: Expenses -> Salaries Expense
            $employeeAdvancesAccount = Account::where('code', 'LIKE', '13%')->first(); // Example: Assets -> Current Assets -> Employee Advances
            $salariesPayableAccount = Account::where('code', 'LIKE', '21%')->first(); // Example: Liabilities -> Current Liabilities -> Salaries Payable

            if (!$salaryExpenseAccount || !$employeeAdvancesAccount || !$salariesPayableAccount) {
                throw new \Exception('One or more required accounts not found. Please configure them.');
            }

            // Debit: Salaries Expense Account (Gross Salary)
            Entry::create([
                'transaction_id' => $transaction->id,
                'account_id' => $salaryExpenseAccount->id,
                'debit' => $grossSalary,
                'credit' => 0,
                'description' => 'مصروف راتب الموظف: ' . $employee->name,
            ]);

            // Credit: Employee Advances Account (Deducted Loans Amount)
            if ($deductedLoansAmount > 0) {
                Entry::create([
                    'transaction_id' => $transaction->id,
                    'account_id' => $employeeAdvancesAccount->id,
                    'debit' => 0,
                    'credit' => $deductedLoansAmount,
                    'description' => 'خصم سلف من راتب الموظف: ' . $employee->name,
                ]);
            }

            // Credit: Cash or Bank Account (Net Salary)
            Entry::create([
                'transaction_id' => $transaction->id,
                'account_id' => $request->cash_bank_account_id,
                'debit' => 0,
                'credit' => $netSalary,
                'description' => 'صافي راتب الموظف المدفوع: ' . $employee->name,
            ]);

            DB::commit();

            return response()->json(['message' => 'تم دفع الراتب بنجاح.', 'transaction' => $transaction->load('entries')], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'فشل دفع الراتب.', 'error' => $e->getMessage()], 500);
        }
    }

    public function getStatement(Request $request, Employee $employee)
    {
        $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
        ]);

        $query = Entry::whereHas('transaction.source', function ($q) use ($employee) {
            $q->where('source_type', Employee::class)->where('source_id', $employee->id);
        })
            ->orWhereHas('transaction.source', function ($q) use ($employee) {
                $q->where('source_type', EmployeeLoan::class)->whereHas('employee', function ($q2) use ($employee) {
                    $q2->where('id', $employee->id);
                });
            });

        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $statement = $query->with('transaction', 'account')->orderBy('created_at')->get();

        return response()->json($statement);
    }

    public function getLoans(Employee $employee)
    {
        return response()->json($employee->loans()->with('transaction')->get());
    }
}