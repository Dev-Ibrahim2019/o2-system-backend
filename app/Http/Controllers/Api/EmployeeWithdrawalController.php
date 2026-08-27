<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\Employee;
use App\Models\EmployeeWithdrawal;
use App\Services\Accounting\EmployeeAccountingService;
use App\Services\Accounting\SubledgerService;
use App\Services\Accounting\TransactionPostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeWithdrawalController extends ApiController
{
    public function __construct(
        private readonly EmployeeAccountingService $accounting,
        private readonly TransactionPostingService $posting,
        private readonly SubledgerService $subledger,
    ) {}

    public function index(Employee $employee): JsonResponse
    {
        $rows = $employee->withdrawals()
            ->with('cashAccount:id,code,name')
            ->orderByDesc('date')->orderByDesc('id')->get();

        return $this->success('Employee withdrawals fetched', [
            'withdrawals' => $rows,
            'total_posted' => round((float) $rows->where('status', 'posted')->sum('amount'), 2),
            'outstanding_advance' => $this->subledger->getBalance('employee', $employee->id, '1130'),
        ]);
    }

    public function store(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.001'],
            'cash_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:1000'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        try {
            $withdrawal = DB::transaction(function () use ($data, $employee, $request) {
                $transaction = $this->accounting->recordWithdrawal(
                    employee: $employee,
                    amount: (float) $data['amount'],
                    cashAccountId: (int) $data['cash_account_id'],
                    date: $data['date'],
                    description: $data['description'] ?? null,
                    branchId: $data['branch_id'] ?? $employee->branch_id,
                );

                return EmployeeWithdrawal::create([
                    'employee_id' => $employee->id,
                    'amount' => $data['amount'],
                    'date' => $data['date'],
                    'cash_account_id' => $data['cash_account_id'],
                    'description' => $data['description'] ?? "سحب موظف: {$employee->name}",
                    'status' => 'posted',
                    'transaction_id' => $transaction->id,
                    'created_by' => $request->user()?->id,
                ])->load('cashAccount:id,code,name');
            });

            return $this->success('تم تسجيل سحب الموظف', $withdrawal, 201);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function destroy(Request $request, Employee $employee, EmployeeWithdrawal $withdrawal): JsonResponse
    {
        abort_unless($withdrawal->employee_id === $employee->id, 404);
        if ($withdrawal->status !== 'posted' || !$withdrawal->transaction_id) {
            return $this->error('السحب ملغي مسبقاً أو لا يملك قيداً قابلاً للعكس', 422);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'date' => ['nullable', 'date'],
        ]);

        try {
            DB::transaction(function () use ($withdrawal, $data) {
                $reversal = $this->posting->reverse(
                    $withdrawal->transaction,
                    $data['reason'] ?? 'إلغاء سحب موظف',
                    $data['date'] ?? now()->toDateString(),
                );
                $withdrawal->update([
                    'status' => 'reversed',
                    'reversal_transaction_id' => $reversal->id,
                ]);
            });

            return $this->success('تم إلغاء السحب وعكس القيد المحاسبي', []);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
