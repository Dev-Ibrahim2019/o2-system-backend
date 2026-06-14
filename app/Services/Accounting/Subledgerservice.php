<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Entry;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * ══════════════════════════════════════════════════════════════
 * SERVICE: SubledgerService
 * ══════════════════════════════════════════════════════════════
 *
 * مسؤولية واحدة: استخراج كشوف الحساب لأي كيان (موظف/عميل/مورد)
 * عبر subledger_type + subledger_id في entries
 *
 * لا ينشئ حسابات — هذا دور AccountCreationService (المحذوف)
 * لا يُنشئ قيوداً — هذا دور TransactionPostingService
 *
 * الاستخدام:
 *   $service->getStatement('employee', 33, '2026-01-01', '2026-12-31')
 *   $service->getBalance('employee', 33, '1130')
 *   $service->getAllBalances('employee', 33)
 * ══════════════════════════════════════════════════════════════
 */
class SubledgerService
{
    /**
     * كشف حساب كامل لكيان على حساب معين
     *
     * @param string      $type      'employee' | 'customer' | 'supplier'
     * @param int         $id        ID الكيان
     * @param string      $accountCode كود الحساب (مثال: '1130', '2120')
     * @param string|null $from
     * @param string|null $to
     * @param int|null    $branchId
     */
    public function getStatement(
        string  $type,
        int     $id,
        string  $accountCode,
        ?string $from = null,
        ?string $to   = null,
        ?int    $branchId = null,
    ): array {
        $account = Account::where('code', $accountCode)->firstOrFail();

        $fromDate = $from ? Carbon::parse($from)->startOfDay() : null;
        $toDate   = $to   ? Carbon::parse($to)->endOfDay()     : null;

        // ── Opening Balance ────────────────────────────────────────────────
        $openingQuery = Entry::query()
            ->forSubledgerAccount($type, $id, $account->id)
            ->whereHas('transaction', function ($q) use ($fromDate, $branchId) {
                $q->where('status', 'posted');
                if ($fromDate) $q->where('date', '<', $fromDate);
                if ($branchId) $q->where('branch_id', $branchId);
            });

        $opening = $openingQuery
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->first();

        $openingBalance = $this->calcBalance($account, (float)$opening->d, (float)$opening->c);

        // ── Period Entries ─────────────────────────────────────────────────
        $entries = Entry::query()
            ->forSubledgerAccount($type, $id, $account->id)
            ->with(['transaction:id,transaction_number,date,type,description,status'])
            ->whereHas('transaction', function ($q) use ($fromDate, $toDate, $branchId) {
                $q->where('status', 'posted');
                if ($fromDate) $q->where('date', '>=', $fromDate);
                if ($toDate)   $q->where('date', '<=', $toDate);
                if ($branchId) $q->where('branch_id', $branchId);
            })
            ->orderBy('id')
            ->get();

        // ── Running Balance ────────────────────────────────────────────────
        $runningBalance = $openingBalance;
        $lines = $entries->map(function (Entry $entry) use ($account, &$runningBalance) {
            $d = (float) $entry->debit;
            $c = (float) $entry->credit;
            $runningBalance += $this->calcBalance($account, $d, $c);

            return [
                'date'               => $entry->transaction->date->format('Y-m-d'),
                'transaction_number' => $entry->transaction->transaction_number,
                'type'               => $entry->transaction->type,
                'description'        => $entry->description ?? $entry->transaction->description,
                'debit'              => $d,
                'credit'             => $c,
                'balance'            => round($runningBalance, 3),
            ];
        });

        return [
            'account'         => ['code' => $account->code, 'name' => $account->name],
            'subledger'       => ['type' => $type, 'id' => $id],
            'period'          => ['from' => $from, 'to' => $to],
            'opening_balance' => round($openingBalance, 3),
            'total_debit'     => round((float) $entries->sum('debit'), 3),
            'total_credit'    => round((float) $entries->sum('credit'), 3),
            'closing_balance' => round($runningBalance, 3),
            'lines'           => $lines,
        ];
    }

    /**
     * رصيد كيان على حساب معين حتى تاريخ معين
     */
    public function getBalance(
        string  $type,
        int     $id,
        string  $accountCode,
        ?string $asOf    = null,
        ?int    $branchId = null,
    ): float {
        $account = Account::where('code', $accountCode)->first();
        if (! $account) return 0.0;

        $query = Entry::query()
            ->forSubledgerAccount($type, $id, $account->id)
            ->whereHas('transaction', function ($q) use ($asOf, $branchId) {
                $q->where('status', 'posted');
                if ($asOf)     $q->where('date', '<=', $asOf);
                if ($branchId) $q->where('branch_id', $branchId);
            })
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->first();

        return $this->calcBalance($account, (float)$query->d, (float)$query->c);
    }

    /**
     * كل أرصدة موظف دفعة واحدة (advance + loan + salary)
     * يُستخدم في account statement API
     */
    public function getEmployeeBalances(int $employeeId, ?string $asOf = null): array
    {
        return [
            'outstanding_advance' => $this->getBalance('employee', $employeeId, '1130', $asOf),
            'outstanding_loan'    => $this->getBalance('employee', $employeeId, '2130', $asOf),
            'accrued_salary'      => $this->getBalance('employee', $employeeId, '2120', $asOf),
        ];
    }

    /**
     * كل أرصدة عميل
     */
    public function getCustomerBalance(int $customerId, ?string $asOf = null): float
    {
        return $this->getBalance('customer', $customerId, '1120', $asOf);
    }

    /**
     * كل أرصدة مورد
     */
    public function getSupplierBalance(int $supplierId, ?string $asOf = null): float
    {
        return $this->getBalance('supplier', $supplierId, '2110', $asOf);
    }

    /**
     * كشف حساب موظف الكامل (سلف + رواتب معاً)
     */
    public function getEmployeeFullStatement(
        int     $employeeId,
        ?string $from = null,
        ?string $to   = null,
    ): array {
        return [
            'advance' => $this->getStatement('employee', $employeeId, '1130', $from, $to),
            'salary'  => $this->getStatement('employee', $employeeId, '2120', $from, $to),
        ];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function calcBalance(Account $account, float $debit, float $credit): float
    {
        return in_array($account->type, ['asset', 'expense'])
            ? ($debit - $credit)
            : ($credit - $debit);
    }
}
