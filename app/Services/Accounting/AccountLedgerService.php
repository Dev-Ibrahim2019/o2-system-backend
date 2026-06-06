<?php



namespace App\Services\Accounting;



use App\Models\Account;

use App\Models\Entry;

use Carbon\Carbon;



class AccountLedgerService

{

    /**

     * كشف حساب كامل

     */

    public function getStatement(

        Account $account,

        ?string $from = null,

        ?string $to = null,

        ?int $branchId = null,

        ?int $costCenterId = null,

    ): array {



        $fromDate = $from

            ? Carbon::parse($from)->startOfDay()

            : null;



        $toDate = $to

            ? Carbon::parse($to)->endOfDay()

            : null;



        // ─────────────────────────────────────────

        // Opening Balance

        // ─────────────────────────────────────────



        $openingQuery = Entry::query()

            ->where('account_id', $account->id)

            ->whereHas('transaction', function ($q) use ($fromDate, $branchId) {



                $q->where('status', 'posted');



                if ($fromDate) {

                    $q->where('date', '<', $fromDate);
                }



                if ($branchId) {

                    $q->where('branch_id', $branchId);
                }
            });



        if ($costCenterId) {

            $openingQuery->where('cost_center_id', $costCenterId);
        }



        $openingDebit = $openingQuery->sum('debit');

        $openingCredit = $openingQuery->sum('credit');



        $openingBalance = $this->calculateBalance(

            $account,

            $openingDebit,

            $openingCredit

        );



        // ─────────────────────────────────────────

        // Entries Query

        // ─────────────────────────────────────────



        $entriesQuery = Entry::query()

            ->with([

                'transaction',

                'costCenter',

            ])

            ->where('account_id', $account->id)

            ->whereHas('transaction', function ($q) use (

                $fromDate,

                $toDate,

                $branchId

            ) {



                $q->where('status', 'posted');



                if ($fromDate) {

                    $q->where('date', '>=', $fromDate);
                }



                if ($toDate) {

                    $q->where('date', '<=', $toDate);
                }



                if ($branchId) {

                    $q->where('branch_id', $branchId);
                }
            })

            ->orderBy('id');



        if ($costCenterId) {

            $entriesQuery->where('cost_center_id', $costCenterId);
        }



        $entries = $entriesQuery->get();



        // ─────────────────────────────────────────

        // Running Balance

        // ─────────────────────────────────────────



        $runningBalance = $openingBalance;



        $statementEntries = [];



        foreach ($entries as $entry) {



            $movement = $this->calculateBalance(

                $account,

                $entry->debit,

                $entry->credit

            );



            $runningBalance += $movement;



            $statementEntries[] = [

                'date' => $entry->transaction->date,

                'transaction_number' => $entry->transaction->transaction_number,

                'description' => $entry->description,

                'debit' => (float) $entry->debit,

                'credit' => (float) $entry->credit,

                'balance' => $runningBalance,

                'transaction_type' => $entry->transaction->type,

                'cost_center' => $entry->costCenter?->name,

            ];
        }



        return [

            'account' => $account->name,

            'account_code' => $account->code,



            'opening_balance' => $openingBalance,



            'entries' => $statementEntries,



            'total_debit' => (float) $entries->sum('debit'),

            'total_credit' => (float) $entries->sum('credit'),



            'closing_balance' => $runningBalance,

        ];
    }



    /**

     * حساب الرصيد حسب طبيعة الحساب

     */

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