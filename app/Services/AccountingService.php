<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Entry;
use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * خدمة القيود المحاسبية — إنشاء قيد يومية عند سداد فاتورة
 *
 * القيد:
 *   من ح/ الصندوق (أو البنك)  → مدين
 *     إلى ح/ إيرادات المبيعات → دائن
 */
class AccountingService
{
    /**
     * إنشاء قيد محاسبي تلقائي عند سداد فاتورة بالكامل
     *
     * debit  : حساب الصندوق (cash) أو البنك حسب طريقة الدفع
     * credit : حساب إيرادات المبيعات (revenue)
     */
    public function createJournalEntryForInvoice(Invoice $invoice): ?Transaction
    {
        if ($invoice->status !== 'paid') {
            return null;
        }

        if ($invoice->order_id && Transaction::forSource($invoice->order)->exists()) {
            return null;
        }

        $total = (float) $invoice->total;

        if ($total <= 0) {
            return null;
        }

        $cashAccount = $this->findCashAccount($invoice->payment_method);
        $revenueAccount = $this->findRevenueAccount();

        if (! $cashAccount || ! $revenueAccount) {
            return null;
        }

        return DB::transaction(function () use ($invoice, $cashAccount, $revenueAccount, $total) {
            $transaction = Transaction::create([
                'transaction_number' => Transaction::generateNumber(),
                'date' => now(),
                'reference' => $invoice->number,
                'type' => 'sales',
                'status' => 'posted',
                'description' => "قيد مبيعات — فاتورة {$invoice->number} / طلب {$invoice->order?->order_number}",
                'branch_id' => $invoice->branch_id,
                'source_type' => $invoice->order ? get_class($invoice->order) : null,
                'source_id' => $invoice->order_id,
                'posted_at' => now(),
            ]);

            Entry::create([
                'transaction_id' => $transaction->id,
                'account_id' => $cashAccount->id,
                'debit' => $total,
                'credit' => 0,
                'description' => "قبض من {$invoice->payment_method} — فاتورة {$invoice->number}",
                'sort_order' => 1,
            ]);

            Entry::create([
                'transaction_id' => $transaction->id,
                'account_id' => $revenueAccount->id,
                'debit' => 0,
                'credit' => $total,
                'description' => "إيرادات مبيعات — فاتورة {$invoice->number}",
                'sort_order' => 2,
            ]);

            return $transaction;
        });
    }

    /**
     * جلب حساب الصندوق حسب طريقة الدفع
     */
    protected function findCashAccount(string $method): ?Account
    {
        $mapping = [
            'cash' => ['code' => '101', 'type' => 'asset'],
            'card' => ['code' => '102', 'type' => 'asset'],
            'bank' => ['code' => '103', 'type' => 'asset'],
            'wallet' => ['code' => '104', 'type' => 'asset'],
            'account' => ['code' => '105', 'type' => 'asset'],
            'mixed' => ['code' => '101', 'type' => 'asset'],
        ];

        $config = $mapping[$method] ?? $mapping['cash'];

        return Account::where('code', $config['code'])
            ->where('type', $config['type'])
            ->where('is_active', true)
            ->first();
    }

    /**
     * جلب حساب إيرادات المبيعات
     */
    protected function findRevenueAccount(): ?Account
    {
        return Account::where('type', 'revenue')
            ->where('is_active', true)
            ->first();
    }
}
