<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Entry;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Throwable;

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
                'document_number'    => $txn->reference ?: $txn->transaction_number,
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
        string  $mode = 'simple',
        string  $statementType = 'all',
    ): array {
        $fromDate = $from ? Carbon::parse($from)->startOfDay() : null;
        $toDate   = $to   ? Carbon::parse($to)->endOfDay()     : null;
        $mode = in_array($mode, ['simple', 'detailed'], true) ? $mode : 'simple';
        $statementType = $this->normalizeStatementFilter($statementType);

        $openingEntries = Entry::query()
            ->forSubledger($type, $id)
            ->with('account:id,code,name,type')
            ->whereHas('transaction', function ($q) use ($fromDate, $branchId) {
                $q->where('status', 'posted');
                if ($fromDate) $q->where('date', '<', $fromDate);
                if ($branchId) $q->where('branch_id', $branchId);
            })
            ->get();
        $openingBalance = $this->computeNetBalance($openingEntries);

        $entries = Entry::query()
            ->forSubledger($type, $id)
            ->with([
                'account:id,code,name,type',
                'transaction:id,transaction_number,date,type,description,status,reference,source_type,source_id,branch_id',
                'transaction.source',
                'transaction.branch:id,name',
                'transaction.entries.account:id,code,name',
                'transaction.entries.costCenter:id,name',
            ])
            ->whereHas('transaction', function ($q) use ($fromDate, $toDate, $branchId) {
                $q->where('status', 'posted');
                if ($fromDate) $q->where('date', '>=', $fromDate);
                if ($toDate)   $q->where('date', '<=', $toDate);
                if ($branchId) $q->where('branch_id', $branchId);
            })
            ->orderBy('id')
            ->get();

        $runningBalance = $openingBalance;
        $allLines = $entries->map(function (Entry $entry) use (&$runningBalance, $mode) {
            $d = (float) $entry->debit;
            $c = (float) $entry->credit;
            $account = $entry->account;
            $txn = $entry->transaction;

            if ($account) {
                $isDebitNormal = in_array($account->type, ['asset', 'expense'], true);
                $runningBalance += $isDebitNormal ? ($d - $c) : ($c - $d);
            } else {
                $runningBalance += ($c - $d);
            }

            $line = [
                'date'               => $txn->date->format('Y-m-d'),
                'transaction_number' => $txn->transaction_number,
                'document_number'    => $txn->reference ?: $txn->transaction_number,
                'transaction_id'     => $txn->id,
                'type'               => $txn->type,
                'reference'          => $txn->reference,
                'description'        => $entry->description ?? $txn->description,
                'account_name'       => $account?->name ?? '-',
                'account_code'       => $account?->code ?? '-',
                'debit'              => $d,
                'credit'             => $c,
                'balance'            => round($runningBalance, 3),
                'running_balance'    => round($runningBalance, 3),
                'source_type'        => $txn->source_type,
                'source_id'          => $txn->source_id,
                'source_label'       => $txn->source_type ? class_basename($txn->source_type) : null,
                'branch_id'          => $txn->branch_id,
                'branch_name'        => $txn->relationLoaded('branch') && $txn->branch ? $txn->branch->name : null,
                'status'             => $txn->status,
                'items'              => [],
                'payments_data'      => [],
                'journal_entries'    => [],
                'has_discounts'      => false,
                'discount_amount'    => 0,
                'discount_percent'   => 0,
            ];

            $line = StatementClassifier::classifyLine($line);
            $line = $this->retagSubledgerLine($line);

            return $mode === 'detailed'
                ? $this->enrichLineDetails($line, $entry)
                : $line;
        })->values()->all();

        $lines = array_values(array_filter(
            $allLines,
            fn(array $line) => $this->typeMatches($line, $statementType)
        ));

        $totalsByAccount = $entries->groupBy('account_id')->map(function ($group) {
            $account = $group->first()->account;
            return [
                'account_id'   => $group->first()->account_id,
                'account_code' => $account?->code ?? '-',
                'account_name' => $account?->name ?? '-',
                'debit'        => round((float) $group->sum('debit'), 3),
                'credit'       => round((float) $group->sum('credit'), 3),
                'net'          => round(
                    $account && in_array($account->type, ['asset', 'expense'], true)
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
            'total_debit'     => round((float) collect($lines)->sum('debit'), 3),
            'total_credit'    => round((float) collect($lines)->sum('credit'), 3),
            'closing_balance' => count($lines) > 0 ? round((float) ($lines[array_key_last($lines)]['running_balance'] ?? $openingBalance), 3) : round($openingBalance, 3),
            'lines'           => $lines,
            'accounts'        => $totalsByAccount,
            'mode'            => $mode,
            'type'            => $statementType,
        ];
    }

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


    private function normalizeStatementFilter(?string $filter): string
    {
        $filter = $filter ?: 'all';
        $aliases = [
            'payments' => 'payment',
            'receipts' => 'receipt',
            'collections' => 'receipt',
            'purchases' => 'purchase',
            'returns' => 'return',
            'credit_notes' => 'credit_note',
            'debit_notes' => 'debit_note',
            'journals' => 'journal',
            'discounts' => 'discount',
        ];

        return $aliases[$filter] ?? $filter;
    }

    private function retagSubledgerLine(array $line): array
    {
        $txnType = strtolower((string) ($line['type'] ?? ''));
        $number = strtoupper((string) ($line['transaction_number'] ?? ''));
        $reference = strtolower((string) ($line['reference'] ?? ''));
        $description = strtolower((string) ($line['description'] ?? ''));

        if ($txnType === 'receipt' || str_starts_with($number, 'RCP')) {
            return $this->tagLine($line, 'receipt', "\u{062A}\u{062D}\u{0635}\u{064A}\u{0644}\u{0627}\u{062A}", "\u{0633}\u{0646}\u{062F}\u{0020}\u{0642}\u{0628}\u{0636}");
        }
        if ($txnType === 'payment' || str_starts_with($number, 'PMT')) {
            return $this->tagLine($line, 'payment', "\u{062F}\u{0641}\u{0639}\u{0627}\u{062A}", "\u{0633}\u{0646}\u{062F}\u{0020}\u{062F}\u{0641}\u{0639}");
        }
        if ($txnType === 'sale' || str_starts_with($number, 'INV')) {
            return $this->tagLine($line, 'sales', "\u{0627}\u{0644}\u{0645}\u{0628}\u{064A}\u{0639}\u{0627}\u{062A}", "\u{0641}\u{0627}\u{062A}\u{0648}\u{0631}\u{0629}\u{0020}\u{0645}\u{0628}\u{064A}\u{0639}\u{0627}\u{062A}");
        }
        if ($txnType === 'purchase' || str_starts_with($number, 'BILL')) {
            return $this->tagLine($line, 'purchase', "\u{0627}\u{0644}\u{0645}\u{0634}\u{062A}\u{0631}\u{064A}\u{0627}\u{062A}", "\u{0641}\u{0627}\u{062A}\u{0648}\u{0631}\u{0629}\u{0020}\u{0634}\u{0631}\u{0627}\u{0621}");
        }
        if (str_starts_with($number, 'CN') || str_contains($reference, 'credit') || str_contains($description, 'credit')) {
            return $this->tagLine($line, 'credit_note', "\u{0625}\u{0634}\u{0639}\u{0627}\u{0631}\u{0020}\u{062F}\u{0627}\u{0626}\u{0646}", "\u{0625}\u{0634}\u{0639}\u{0627}\u{0631}\u{0020}\u{062F}\u{0627}\u{0626}\u{0646}");
        }
        if (str_starts_with($number, 'DN') || str_contains($reference, 'debit') || str_contains($description, 'debit')) {
            return $this->tagLine($line, 'debit_note', "\u{0625}\u{0634}\u{0639}\u{0627}\u{0631}\u{0020}\u{0645}\u{062F}\u{064A}\u{0646}", "\u{0625}\u{0634}\u{0639}\u{0627}\u{0631}\u{0020}\u{0645}\u{062F}\u{064A}\u{0646}");
        }
        if ($txnType === 'journal') {
            return $this->tagLine($line, 'journal', "\u{0627}\u{0644}\u{0642}\u{064A}\u{0648}\u{062F}", "\u{0627}\u{0644}\u{0642}\u{064A}\u{0648}\u{062F}");
        }

        return $line;
    }

    private function tagLine(array $line, string $type, string $label, string $documentType): array
    {
        $line['movement_type'] = $type;
        $line['movement_label'] = $label;
        $line['document_type'] = $documentType;
        return $line;
    }

    private function typeMatches(array $line, string $filter): bool
    {
        if ($filter === 'all') {
            return true;
        }

        $movementType = (string) ($line['movement_type'] ?? '');
        $groups = [
            'sales' => ['sales'],
            'purchase' => ['purchase'],
            'payment' => ['payment', 'supplier_payment', 'advance_repayment', 'loan_repayment', 'salary_payment'],
            'receipt' => ['receipt', 'customer_payment'],
            'credit_note' => ['credit_note', 'discount'],
            'debit_note' => ['debit_note'],
            'journal' => ['journal'],
            'return' => ['return', 'refund'],
            'discount' => ['discount', 'credit_note'],
        ];

        return in_array($movementType, $groups[$filter] ?? [$filter], true);
    }

    private function enrichLineDetails(array $line, Entry $entry): array
    {
        $transaction = $entry->transaction;
        $source = $transaction?->source;

        if ($source) {
            $items = $this->extractItems($source);
            if ($items !== []) {
                $line['items'] = $items;
                $line['total_items'] = count($items);
                $line['total_discount_amount'] = round((float) collect($items)->sum('discount_amount'), 3);
                $line['discount_amount'] = $line['total_discount_amount'];
                $line['discount_percent'] = round((float) collect($items)->sum('discount_percent'), 3);
                $line['discount_count'] = collect($items)->filter(fn($item) => (float) ($item['discount_amount'] ?? 0) > 0)->count();
                $line['has_discounts'] = $line['discount_count'] > 0;
            }

            $line['invoice_details'] = $this->buildDocumentDetails($source);
            if (($source->id ?? null) && str_contains(class_basename($source), 'Invoice')) {
                $line['invoice_id'] = (int) $source->id;
                $line['document_id'] = (int) $source->id;
            }

            $payments = $this->extractPayments($source);
            if ($payments !== []) {
                $line['payments_data'] = $payments;
            }
        }

        if ($transaction && $transaction->relationLoaded('entries')) {
            $line['journal_entries'] = $transaction->entries->map(fn($journalEntry) => [
                'account_code' => $journalEntry->account?->code,
                'account_name' => $journalEntry->account?->name,
                'debit'        => (float) $journalEntry->debit,
                'credit'       => (float) $journalEntry->credit,
                'description'  => $journalEntry->description,
                'cost_center'  => $journalEntry->costCenter?->name,
            ])->values()->all();
            $line['journal_number'] = $transaction->transaction_number;
            $line['journal_status'] = $transaction->status;
        }

        return $line;
    }

    private function extractItems(object $source): array
    {
        try {
            if (method_exists($source, 'items')) {
                $source->loadMissing('items');
                return $source->items->map(fn($item) => $this->mapStatementItem($item))->values()->all();
            }
        } catch (Throwable) {
            return [];
        }

        return [];
    }

    private function extractPayments(object $source): array
    {
        try {
            if (method_exists($source, 'payments')) {
                $source->loadMissing('payments');
                return $source->payments->map(fn($payment) => [
                    'method'           => $payment->payment_method ?? $payment->method ?? null,
                    'amount'           => (float) ($payment->amount ?? 0),
                    'reference_number' => $payment->reference_number ?? $payment->reference ?? null,
                    'paid_at'          => $payment->created_at?->format('Y-m-d H:i'),
                ])->values()->all();
            }
        } catch (Throwable) {
            return [];
        }

        return [];
    }

    private function mapStatementItem(object $item): array
    {
        $quantity = (float) ($item->quantity ?? $item->qty ?? 0);
        $unitPrice = (float) ($item->unit_price ?? $item->price ?? 0);
        $total = (float) ($item->total ?? $item->line_total ?? ($quantity * $unitPrice));
        $discount = (float) ($item->discount_amount ?? $item->discount ?? 0);

        return [
            'product_name'            => $item->product_name ?? $item->item_name ?? $item->name ?? '-',
            'product_name_ar'         => $item->product_name_ar ?? $item->item_name_ar ?? $item->product_name ?? $item->item_name ?? null,
            'quantity'                => $quantity,
            'unit_price'              => $unitPrice,
            'total'                   => $total,
            'discount_amount'         => $discount,
            'discount_percent'        => (float) ($item->discount_percent ?? 0),
            'discount_apply_strategy' => $item->discount_apply_strategy ?? null,
            'tax_rate'                => (float) ($item->tax_rate ?? 0),
            'tax_amount'              => (float) ($item->tax_amount ?? 0),
            'department'              => $item->department?->name ?? null,
            'item_code'               => $item->item_id ?? $item->product_id ?? null,
            'item_id'                 => $item->item_id ?? $item->product_id ?? null,
            'barcode'                 => $item->barcode ?? null,
        ];
    }

    private function buildDocumentDetails(object $source): array
    {
        return [
            'invoice_number' => $source->number ?? $source->invoice_number ?? $source->order_number ?? null,
            'invoice_status' => $source->status ?? null,
            'invoice_date'   => isset($source->invoice_date) && $source->invoice_date ? $source->invoice_date->format('Y-m-d') : ($source->date ?? null),
            'subtotal'       => (float) ($source->subtotal ?? 0),
            'discount'       => (float) ($source->discount ?? 0),
            'total'          => (float) ($source->total ?? $source->amount ?? 0),
            'payment_method' => $source->payment_method ?? null,
            'notes'          => $source->notes ?? null,
        ];
    }

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

