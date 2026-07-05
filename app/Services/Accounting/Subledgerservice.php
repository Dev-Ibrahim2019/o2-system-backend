<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Entry;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
 * SERVICE: SubledgerService
 * â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
 *
 * ظ…ط³ط¤ظˆظ„ظٹط© ظˆط§ط­ط¯ط©: ط§ط³طھط®ط±ط§ط¬ ظƒط´ظˆظپ ط§ظ„ط­ط³ط§ط¨ ظ„ط£ظٹ ظƒظٹط§ظ† (ظ…ظˆط¸ظپ/ط¹ظ…ظٹظ„/ظ…ظˆط±ط¯)
 * ط¹ط¨ط± subledger_type + subledger_id ظپظٹ entries
 *
 * ظ„ط§ ظٹظ†ط´ط¦ ط­ط³ط§ط¨ط§طھ â€” ظ‡ط°ط§ ط¯ظˆط± AccountCreationService (ط§ظ„ظ…ط­ط°ظˆظپ)
 * ظ„ط§ ظٹظڈظ†ط´ط¦ ظ‚ظٹظˆط¯ط§ظ‹ â€” ظ‡ط°ط§ ط¯ظˆط± TransactionPostingService
 *
 * ط§ظ„ط§ط³طھط®ط¯ط§ظ…:
 *   $service->getStatement('employee', 33, '2026-01-01', '2026-12-31')
 *   $service->getSupplierFullStatement(33)
 *   $service->getBalance('employee', 33, '1130')
 *   $service->getAllBalances('employee', 33)
 * â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
 */
class SubledgerService
{
    /**
     * ظƒط´ظپ ط­ط³ط§ط¨ ظƒط§ظ…ظ„ ظ„ظƒظٹط§ظ† ط¹ظ„ظ‰ ط­ط³ط§ط¨ ظ…ط¹ظٹظ†
     *
     * @param string      $type      'employee' | 'customer' | 'supplier'
     * @param int         $id        ID ط§ظ„ظƒظٹط§ظ†
     * @param string      $accountCode ظƒظˆط¯ ط§ظ„ط­ط³ط§ط¨ (ظ…ط«ط§ظ„: '1130', '2120')
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

        // â”€â”€ Opening Balance â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

        // â”€â”€ Period Entries â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $entries = Entry::query()
            ->forSubledgerAccount($type, $id, $account->id)
            ->with([
                'transaction:id,transaction_number,date,type,description,status,reference,source_type,source_id,branch_id',
                'transaction.source',
                'transaction.branch:id,name',
            ])
            ->whereHas('transaction', function ($q) use ($fromDate, $toDate, $branchId) {
                $q->where('status', 'posted');
                if ($fromDate) $q->where('date', '>=', $fromDate);
                if ($toDate)   $q->where('date', '<=', $toDate);
                if ($branchId) $q->where('branch_id', $branchId);
            })
            ->orderBy('id')
            ->get();

        // â”€â”€ Running Balance â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $runningBalance = $openingBalance;
        $lines = $entries->map(function (Entry $entry) use ($account, &$runningBalance) {
            $d = (float) $entry->debit;
            $c = (float) $entry->credit;
            $txn = $entry->transaction;
            $runningBalance += $this->calcBalance($account, $d, $c);

            return [
                'date'               => $txn->date->format('Y-m-d'),
                'transaction_number' => $txn->transaction_number,
                'transaction_id'     => $txn->id,
                'type'               => $txn->type,
                'reference'          => $txn->reference,
                'description'        => $entry->description ?? $txn->description,
                'debit'              => $d,
                'credit'             => $c,
                'balance'            => round($runningBalance, 3),
                'source_type'        => $txn->source_type,
                'source_id'          => $txn->source_id,
                'source_label'       => $txn->source_type ? class_basename($txn->source_type) : null,
                'branch_id'          => $txn->branch_id,
                'branch_name'        => $txn->relationLoaded('branch') && $txn->branch ? $txn->branch->name : null,
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

    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // âœ… NEW: ظƒط´ظپ ط­ط³ط§ط¨ ظƒط§ظ…ظ„ ظ„ظƒظٹط§ظ† ط¨ط¬ظ…ظٹط¹ ط­ط³ط§ط¨ط§طھظ‡ (ط¨ط¯ظˆظ† filter ط¹ظ„ظ‰ ط­ط³ط§ط¨ ظ…ط¹ظٹظ†)
    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * ظƒط´ظپ ط­ط³ط§ط¨ ظƒط§ظ…ظ„ ظ„ظƒظٹط§ظ† ط¨ط¬ظ…ظٹط¹ ط­ط³ط§ط¨ط§طھظ‡ (ط¨ط¯ظˆظ† filter ط¹ظ„ظ‰ ط­ط³ط§ط¨ ظ…ط¹ظٹظ†)
     *
     * ظ…ط«ط§ظ„:
     *   $service->getFullStatement('supplier', 1, '2026-01-01', '2026-12-31')
     *
     * @param string      $type  'employee' | 'customer' | 'supplier'
     * @param int         $id    ID ط§ظ„ظƒظٹط§ظ†
     * @param string|null $from
     * @param string|null $to
     * @param int|null    $branchId
     * @return array
     */
    public function getFullStatement(
        string  $type,
        int     $id,
        ?string $from = null,
        ?string $to   = null,
        ?int    $branchId = null,
    ): array {
        $fromDate = $from ? Carbon::parse($from)->startOfDay() : null;
        $toDate   = $to   ? Carbon::parse($to)->endOfDay()     : null;

        // â”€â”€ Opening Balance (all entries before from date) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $openingQuery = Entry::query()
            ->forSubledger($type, $id)
            ->with('account:id,code,name,type')
            ->whereHas('transaction', function ($q) use ($fromDate, $branchId) {
                $q->where('status', 'posted');
                if ($fromDate) $q->where('date', '<', $fromDate);
                if ($branchId) $q->where('branch_id', $branchId);
            });

        $openingEntries = $openingQuery->get();
        $openingBalance = $this->computeNetBalance($openingEntries);

        // â”€â”€ Period Entries â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $entries = Entry::query()
            ->forSubledger($type, $id)
            ->with([
                'account:id,code,name,type',
                'transaction:id,transaction_number,date,type,description,status,source_type,source_id,branch_id',
                'transaction.branch:id,name',
            ])
            ->whereHas('transaction', function ($q) use ($fromDate, $toDate, $branchId) {
                $q->where('status', 'posted');
                if ($fromDate) $q->where('date', '>=', $fromDate);
                if ($toDate)   $q->where('date', '<=', $toDate);
                if ($branchId) $q->where('branch_id', $branchId);
            })
            ->orderBy('id')
            ->get();

        // â”€â”€ Running Balance â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $runningBalance = $openingBalance;
        $lines = $entries->map(function (Entry $entry) use (&$runningBalance) {
            $d = (float) $entry->debit;
            $c = (float) $entry->credit;
            $account = $entry->account;
            $txn = $entry->transaction;

            // Calculate balance effect based on account normal balance type
            if ($account) {
                $isDebitNormal = in_array($account->type, ['asset', 'expense']);
                $effect = $isDebitNormal ? ($d - $c) : ($c - $d);
                $runningBalance += $effect;
            } else {
                $runningBalance += ($c - $d);
            }

            return [
                'date'               => $txn->date->format('Y-m-d'),
                'transaction_number' => $txn->transaction_number,
                'transaction_id'     => $txn->id,
                'type'               => $txn->type,
                'description'        => $entry->description ?? $txn->description,
                'account_name'       => $account?->name ?? 'â€”',
                'account_code'       => $account?->code ?? 'â€”',
                'debit'              => $d,
                'credit'             => $c,
                'balance'            => round($runningBalance, 3),
                'source_type'        => $txn->source_type,
                'source_id'          => $txn->source_id,
                'source_label'       => $txn->source_type ? class_basename($txn->source_type) : null,
                'branch_id'          => $txn->branch_id,
                'branch_name'        => $txn->relationLoaded('branch') && $txn->branch ? $txn->branch->name : null,
            ];
        });

        // â”€â”€ Compute totals across all accounts â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $totalsByAccount = $entries->groupBy('account_id')->map(function ($group) {
            $account = $group->first()->account;
            return [
                'account_id'   => $group->first()->account_id,
                'account_code' => $account?->code ?? 'â€”',
                'account_name' => $account?->name ?? 'â€”',
                'debit'        => round((float) $group->sum('debit'), 3),
                'credit'       => round((float) $group->sum('credit'), 3),
                'net'          => round(
                    $account && in_array($account->type, ['asset', 'expense'])
                        ? (float) $group->sum('debit') - (float) $group->sum('credit')
                        : (float) $group->sum('credit') - (float) $group->sum('debit'),
                    3
                ),
            ];
        })->values();

        return [
            'subledger'       => ['type' => $type, 'id' => $id],
            'period'          => ['from' => $from, 'to' => $to],
            'opening_balance' => round($openingBalance, 3),
            'total_debit'     => round((float) $entries->sum('debit'), 3),
            'total_credit'    => round((float) $entries->sum('credit'), 3),
            'closing_balance' => round($runningBalance, 3),
            'lines'           => $lines,
            'accounts'        => $totalsByAccount,
        ];
    }

    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // âœ… NEW: ط¥ط¬ظ…ط§ظ„ظٹ ط§ظ„ظ…ط¯ظپظˆط¹ط§طھ ظ„ظƒظٹط§ظ† ظپظٹ ظپطھط±ط© ظ…ط¹ظٹظ†ط©
    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * ط¥ط¬ظ…ط§ظ„ظٹ ط§ظ„ظ…ط¯ظپظˆط¹ط§طھ ط§ظ„ظ…ط¯ظپظˆط¹ط© ظ„ظƒظٹط§ظ† ظپظٹ ظپطھط±ط© ظ…ط¹ظٹظ†ط©
     */
    public function getPaymentsTotal(
        string  $type,
        int     $id,
        ?string $from = null,
        ?string $to   = null,
    ): float {
        $fromDate = $from ? Carbon::parse($from)->startOfDay() : null;
        $toDate   = $to   ? Carbon::parse($to)->endOfDay()     : null;

        // Supplier payments = credit entries for the supplier
        // (Debiting AP account, crediting cash = reduction in liability)
        // We sum all credit entries across all accounts for this subledger
        $query = Entry::query()
            ->forSubledger($type, $id)
            ->whereHas('transaction', function ($q) use ($fromDate, $toDate) {
                $q->where('status', 'posted');
                if ($fromDate) $q->where('date', '>=', $fromDate);
                if ($toDate)   $q->where('date', '<=', $toDate);
            });

        // For suppliers: payments are credit entries (reducing AP)
        // But we want total payment amount = sum of credit where account is cash/bank
        // Actually let's just sum all credit entries as a simple metric
        $result = $query
            ->selectRaw('COALESCE(SUM(credit),0) as total_credit, COALESCE(SUM(debit),0) as total_debit')
            ->first();

        // For supplier: payments are the credit side (cash goes out)
        // For customer: payments are the debit side (cash comes in)
        return $type === 'supplier'
            ? round((float) ($result?->total_credit ?? 0), 3)
            : round((float) ($result?->total_debit ?? 0), 3);
    }

    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Balance Methods (unchanged)
    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * ط±طµظٹط¯ ظƒظٹط§ظ† ط¹ظ„ظ‰ ط­ط³ط§ط¨ ظ…ط¹ظٹظ† ط­طھظ‰ طھط§ط±ظٹط® ظ…ط¹ظٹظ†
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
     * ظƒظ„ ط£ط±طµط¯ط© ظ…ظˆط¸ظپ ط¯ظپط¹ط© ظˆط§ط­ط¯ط© (advance + loan + salary)
     * ظٹظڈط³طھط®ط¯ظ… ظپظٹ account statement API
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
     * ظƒظ„ ط£ط±طµط¯ط© ط¹ظ…ظٹظ„
     */
    public function getCustomerBalance(int $customerId, ?string $asOf = null): float
    {
        return $this->getBalance('customer', $customerId, '1120', $asOf);
    }

    /**
     * ظƒظ„ ط£ط±طµط¯ط© ظ…ظˆط±ط¯
     */
    public function getSupplierBalance(int $supplierId, ?string $asOf = null): float
    {
        return $this->getBalance('supplier', $supplierId, '2110', $asOf);
    }

    /**
     * ظƒط´ظپ ط­ط³ط§ط¨ ظ…ظˆط¸ظپ ط§ظ„ظƒط§ظ…ظ„ (ط³ظ„ظپ + ط±ظˆط§طھط¨ ظ…ط¹ط§ظ‹)
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

    // â”€â”€ Private â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private function calcBalance(Account $account, float $debit, float $credit): float
    {
        return in_array($account->type, ['asset', 'expense'])
            ? ($debit - $credit)
            : ($credit - $debit);
    }

    /**
     * ط­ط³ط§ط¨ طµط§ظپظٹ ط§ظ„ط±طµظٹط¯ ظ…ظ† ظ…ط¬ظ…ظˆط¹ط© entries ط¹ط¨ط± ظƒظ„ ط§ظ„ط­ط³ط§ط¨ط§طھ
     */
    private function computeNetBalance(Collection $entries): float
    {
        $balance = 0;
        foreach ($entries as $entry) {
            $account = $entry->account;
            $d = (float) $entry->debit;
            $c = (float) $entry->credit;
            if ($account) {
                $isDebitNormal = in_array($account->type, ['asset', 'expense']);
                $balance += $isDebitNormal ? ($d - $c) : ($c - $d);
            } else {
                $balance += ($c - $d);
            }
        }
        return $balance;
    }
}

