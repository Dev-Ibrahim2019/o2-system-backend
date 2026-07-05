<?php

namespace App\Services\Accounting;

use App\Models\Entry;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Validates journal entries before posting — prevents unbalanced transactions.
 */
class JournalEntryValidationService
{
    private const TOLERANCE = 0.001;

    /**
     * @param  array<int, array{debit?: float|int, credit?: float|int}>  $entries
     */
    public function assertBalanced(array $entries, ?string $context = null): void
    {
        $totalDebit = round(collect($entries)->sum(fn ($e) => (float) ($e['debit'] ?? 0)), 3);
        $totalCredit = round(collect($entries)->sum(fn ($e) => (float) ($e['credit'] ?? 0)), 3);

        if (abs($totalDebit - $totalCredit) > self::TOLERANCE) {
            $suffix = $context ? " ({$context})" : '';
            throw new RuntimeException(
                "القيد المحاسبي غير متوازن{$suffix} — مدين: {$totalDebit} / دائن: {$totalCredit}"
            );
        }
    }

    public function assertTransactionBalanced(Transaction $transaction): void
    {
        $entries = $transaction->relationLoaded('entries')
            ? $transaction->entries
            : $transaction->entries()->get();

        $this->assertCollectionBalanced($entries, "قيد #{$transaction->transaction_number}");
    }

    /**
     * @param  Collection<int, Entry>  $entries
     */
    public function assertCollectionBalanced(Collection $entries, ?string $context = null): void
    {
        $this->assertBalanced(
            $entries->map(fn (Entry $e) => [
                'debit' => (float) $e->debit,
                'credit' => (float) $e->credit,
            ])->all(),
            $context
        );
    }
}
