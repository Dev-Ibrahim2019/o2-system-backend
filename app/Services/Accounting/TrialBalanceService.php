<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Entry;
use Illuminate\Support\Facades\DB;

class TrialBalanceService
{
    public function generate(
        ?string $from = null,
        ?string $to = null,
        ?int $branchId = null,
    ): array {

        $query = Entry::query()
            ->select([
                'account_id',

                DB::raw('SUM(debit) as total_debit'),
                DB::raw('SUM(credit) as total_credit'),
            ])

            ->with('account')

            ->whereHas('transaction', function ($q) use (
                $from,
                $to,
                $branchId
            ) {

                $q->where('status', 'posted');

                if ($from) {
                    $q->whereDate('date', '>=', $from);
                }

                if ($to) {
                    $q->whereDate('date', '<=', $to);
                }

                if ($branchId) {
                    $q->where('branch_id', $branchId);
                }
            })

            ->groupBy('account_id');

        $rows = $query->get();

        $accounts = [];

        $grandDebit = 0;
        $grandCredit = 0;

        foreach ($rows as $row) {

            $account = $row->account;

            $balance = $this->calculateBalance(
                $account,
                $row->total_debit,
                $row->total_credit
            );

            $accounts[] = [
                'account_code' => $account->code,
                'account_name' => $account->name,
                'account_type' => $account->type,

                'debit' => (float) $row->total_debit,
                'credit' => (float) $row->total_credit,

                'balance' => $balance,
            ];

            $grandDebit += $row->total_debit;
            $grandCredit += $row->total_credit;
        }

        return [

            'accounts' => $accounts,

            'total_debit' => (float) $grandDebit,

            'total_credit' => (float) $grandCredit,

            'is_balanced' => abs(
                $grandDebit - $grandCredit
            ) < 0.001,
        ];
    }

    private function calculateBalance(
        Account $account,
        float $debit,
        float $credit
    ): float {

        return in_array(
            $account->type,
            ['asset', 'expense']
        )
            ? ($debit - $credit)
            : ($credit - $debit);
    }
}