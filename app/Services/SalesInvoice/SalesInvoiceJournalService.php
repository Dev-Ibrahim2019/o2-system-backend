<?php

namespace App\Services\SalesInvoice;

use App\Models\Account;
use App\Models\Entry;
use App\Models\SalesInvoice;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalesInvoiceJournalService
{
    /**
     * Post a sales invoice to the general ledger.
     *
     * Double-entry for an APPROVED sales invoice:
     *
     *   DEBIT:  Accounts Receivable (1120) — total amount (subledger: customer)
     *   CREDIT: Sales Revenue (4110) — subtotal - discount
     *   CREDIT: Tax Payable (2140) — tax_total
     *
     * If tax_inclusive:
     *   DEBIT:  Accounts Receivable (1120) — total (inclusive)
     *   CREDIT: Sales Revenue (4110) — total - tax
     *   CREDIT: Tax Payable (2140) — tax
     */
    public function postSalesInvoice(SalesInvoice $invoice, User $user): Transaction
    {
        $arAccount = Account::where('code', '1120')->firstOrFail();
        $revenueAccount = Account::where('code', '4110')->firstOrFail();
        $taxAccount = Account::where('code', '2140')->first();

        $isInclusive = $invoice->tax_treatment === 'inclusive';

        // ── Calculate amounts ──
        $invoiceTotal = (float) $invoice->total;
        $taxTotal = (float) $invoice->tax_total;
        $revenueAmount = $isInclusive
            ? $invoiceTotal - $taxTotal
            : (float) $invoice->subtotal - (float) $invoice->discount_total;

        $discountAmount = (float) $invoice->discount_total;

        // ── Build entries ──
        $entries = [];

        // 1. DEBIT: Accounts Receivable — full invoice total
        $entries[] = [
            'account_id' => $arAccount->id,
            'debit' => $invoiceTotal,
            'credit' => 0,
            'description' => "فاتورة مبيعات {$invoice->number}",
            'subledger_type' => 'customer',
            'subledger_id' => $invoice->customer_id,
            'sort_order' => 1,
        ];

        // 2. CREDIT: Sales Revenue
        $entries[] = [
            'account_id' => $revenueAccount->id,
            'debit' => 0,
            'credit' => $revenueAmount,
            'description' => "إيراد مبيعات {$invoice->number}",
            'sort_order' => 2,
        ];

        // 3. CREDIT: Tax Payable (if any tax)
        if ($taxTotal > 0 && $taxAccount) {
            $entries[] = [
                'account_id' => $taxAccount->id,
                'debit' => 0,
                'credit' => $taxTotal,
                'description' => "ضريبة قيمة مضافة {$invoice->number}",
                'sort_order' => 3,
            ];
        }

        // 4. If there's a discount, post to discount expense (optional — can use 5220)
        if ($discountAmount > 0) {
            $discountAccount = Account::where('code', '5220')->first();
            if ($discountAccount) {
                // Discount is already netted in revenue, but for tracking:
                // We can either:
                // a) Reduce revenue by discount (already done above via $revenueAmount)
                // b) Post discount separately to a contra-revenue or expense account
                // Here we use approach (a) — discount already reduces revenue
            }
        }

        return $this->createTransaction(
            $invoice,
            $entries,
            $user,
            'sale',
            "فاتورة مبيعات {$invoice->number}"
        );
    }

    /**
     * Reverse a transaction (for invoice cancellation).
     * Creates opposite entries.
     */
    public function reverseTransaction(int $transactionId, User $user): Transaction
    {
        $original = Transaction::with('entries')->findOrFail($transactionId);

        if ($original->status === 'cancelled') {
            throw new \RuntimeException('القيد محجوز بالفعل');
        }

        $reversalEntries = [];
        foreach ($original->entries as $entry) {
            $reversalEntries[] = [
                'account_id' => $entry->account_id,
                'debit' => (float) $entry->credit,
                'credit' => (float) $entry->debit,
                'description' => "عكس: {$entry->description}",
                'subledger_type' => $entry->subledger_type,
                'subledger_id' => $entry->subledger_id,
                'sort_order' => $entry->sort_order,
            ];
        }

        $reversal = $this->createTransaction(
            null,
            $reversalEntries,
            $user,
            'adjustment',
            "عكس قيد {$original->transaction_number}",
            $original->id
        );

        // Cancel original
        $original->update(['status' => 'cancelled']);

        return $reversal;
    }

    // ═══════════════════════════════════════════════════════
    //  Internal Helpers
    // ═══════════════════════════════════════════════════════

    protected function createTransaction(
        ?SalesInvoice $invoice,
        array $entries,
        User $user,
        string $type,
        string $description,
        ?int $reversalOfId = null,
    ): Transaction {
        $totalDebit = array_sum(array_column($entries, 'debit'));
        $totalCredit = array_sum(array_column($entries, 'credit'));

        // Validate double-entry
        if (abs($totalDebit - $totalCredit) > 0.001) {
            Log::error("Journal imbalance for invoice {$invoice?->number}: debit={$totalDebit}, credit={$totalCredit}");
            throw new \RuntimeException('القيود المحاسبية غير متوازنة');
        }

        $transaction = Transaction::create([
            'transaction_number' => Transaction::generateNumber(),
            'date' => $invoice?->invoice_date ?? now(),
            'type' => $type,
            'status' => 'posted',
            'description' => $description,
            'branch_id' => $invoice?->branch_id,
            'user_id' => $user->id,
            'source_type' => $invoice ? SalesInvoice::class : null,
            'source_id' => $invoice?->id,
            'reversal_of_id' => $reversalOfId,
            'is_reversal' => $reversalOfId !== null,
            'currency' => $invoice?->currency ?? 'ILS',
            'exchange_rate' => $invoice?->exchange_rate ?? 1,
            'approved_by' => $user->id,
            'approved_at' => now(),
            'posted_at' => now(),
        ]);

        foreach ($entries as $entryData) {
            Entry::create(array_merge($entryData, [
                'transaction_id' => $transaction->id,
            ]));
        }

        return $transaction;
    }
}
