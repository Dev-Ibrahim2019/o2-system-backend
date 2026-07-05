<?php

namespace App\Services\Accounting;

use Illuminate\Http\Request;

class StatementResponseEnricher
{
    /**
     * @param array<string,mixed> $statement
     * @return array<string,mixed>
     */
    public static function enrich(array $statement, Request $request): array
    {
        $lines = StatementClassifier::classifyLines($statement['lines'] ?? []);

        $filters = [
            'type'   => $request->input('type', 'all'),
            'search' => $request->input('search'),
            'branch_id' => $request->input('branch_id'),
            'amount_from' => $request->input('amount_from'),
            'amount_to' => $request->input('amount_to'),
            'invoice_number' => $request->input('invoice_number'),
            'journal_number' => $request->input('journal_number'),
        ];

        $lines = StatementClassifier::applyFilters($lines, $filters);
        $computed = StatementClassifier::computeRunningBalances(
            $lines,
            (float) ($statement['opening_balance'] ?? 0)
        );

        $statement['lines']           = $computed['lines'];
        $statement['opening_balance'] = $computed['opening_balance'];
        $statement['closing_balance'] = $computed['closing_balance'];
        $statement['total_debit']     = $computed['total_debit'];
        $statement['total_credit']    = $computed['total_credit'];
        $statement['summary_by_movement'] = self::summarize($computed['lines']);

        return $statement;
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array<string,array<string,mixed>>
     */
    private static function summarize(array $lines): array
    {
        $summary = [];

        foreach ($lines as $line) {
            $type = $line['movement_type'] ?? StatementClassifier::MOVEMENT_OTHER;
            if (!isset($summary[$type])) {
                $meta = StatementClassifier::getMeta($type);
                $summary[$type] = [
                    'movement_type'  => $type,
                    'movement_label' => $meta['label'],
                    'count'          => 0,
                    'debit'          => 0.0,
                    'credit'         => 0.0,
                ];
            }
            $summary[$type]['count']++;
            $summary[$type]['debit']  += (float) ($line['debit'] ?? 0);
            $summary[$type]['credit'] += (float) ($line['credit'] ?? 0);
        }

        return $summary;
    }
}
