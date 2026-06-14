<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TransactionPostingService
{
    /**
     * إنشاء قيد وترحيله مباشرة (post immediately)
     * للعمليات التلقائية كالرواتب والسلف
     */
    public function createAndPost(array $data, array $entries): Transaction
    {
        return DB::transaction(function () use ($data, $entries) {
            $transaction = $this->create($data, $entries);
            $this->post($transaction);
            return $transaction->fresh(['entries.account']);
        });
    }

    /**
     * إنشاء قيد مسودة
     */
    public function create(array $data, array $entries): Transaction
    {
        return DB::transaction(function () use ($data, $entries) {
            $this->validateEntries($entries);
            $this->validatePeriod($data['date'] ?? now()->toDateString());

            $transaction = Transaction::create(array_merge($data, [
                'transaction_number' => Transaction::generateNumber($data['prefix'] ?? 'JV'),
                'status'             => 'draft',
                'user_id'            => auth()->id(),
                'period_id'          => $this->findPeriodId($data['date'] ?? now()),
            ]));

            foreach ($entries as $index => $entry) {
                $account = Account::find($entry['account_id']);

                if (! $account?->canPost()) {
                    throw new RuntimeException(
                        "الحساب [{$account?->code}] لا يقبل قيوداً مباشرة"
                    );
                }

                $transaction->entries()->create([
                    'account_id'     => $entry['account_id'],
                    'debit'          => $entry['debit'] ?? 0,
                    'credit'         => $entry['credit'] ?? 0,
                    'description'    => $entry['description'] ?? null,
                    'cost_center_id' => $entry['cost_center_id'] ?? null,
                    'subledger_type' => $entry['subledger_type'] ?? null, // ← إضافة
                    'subledger_id'   => $entry['subledger_id'] ?? null,   // ← إضافة
                    'sort_order'     => $index,
                ]);
            }

            return $transaction;
        });
    }

    /**
     * ترحيل قيد (draft → posted)
     * بعد الترحيل لا يمكن التعديل — فقط العكس (Reversal)
     */
    public function post(Transaction $transaction): Transaction
    {
        return DB::transaction(function () use ($transaction) {

            // 🔒 Lock transaction row
            $transaction = Transaction::query()
                ->lockForUpdate()
                ->with([
                    'entries.account',
                ])
                ->findOrFail($transaction->id);

            // ✅ Prevent double posting
            if ($transaction->status !== 'draft') {
                throw new RuntimeException(
                    'يمكن ترحيل المسودات فقط'
                );
            }

            // ✅ Ensure entries exist
            if ($transaction->entries->isEmpty()) {
                throw new RuntimeException(
                    'القيد لا يحتوي على أي سطور'
                );
            }

            // ✅ Recalculate totals safely
            $totalDebit = $transaction->entries->sum('debit');
            $totalCredit = $transaction->entries->sum('credit');

            if (abs($totalDebit - $totalCredit) > 0.001) {
                throw new RuntimeException(
                    "القيد غير متوازن — مدين: {$totalDebit} / دائن: {$totalCredit}"
                );
            }

            // ✅ Validate accounts
            foreach ($transaction->entries as $entry) {

                if (! $entry->account) {
                    throw new RuntimeException(
                        "الحساب غير موجود للسطر {$entry->id}"
                    );
                }

                if (! $entry->account->canPost()) {
                    throw new RuntimeException(
                        "الحساب [{$entry->account->code}] لا يقبل قيوداً مباشرة"
                    );
                }
            }

            // ✅ Post transaction
            $transaction->update([
                'status'    => 'posted',
                'posted_at' => now(),
            ]);

            return $transaction->fresh([
                'entries.account',
            ]);
        });
    }

    /**
     * عكس قيد مرحّل (Reversal)
     * الحل الصحيح بدلاً من الحذف أو التعديل المباشر
     */
    public function reverse(Transaction $transaction, string $reason, ?string $date = null): Transaction
    {
        if ($transaction->status !== 'posted') {
            throw new RuntimeException('يمكن عكس القيود المرحّلة فقط');
        }

        if ($transaction->reversal()->exists()) {
            throw new RuntimeException('هذا القيد تم عكسه مسبقاً');
        }

        $transaction->load('entries');

        return DB::transaction(function () use ($transaction, $reason, $date) {
            $reversalDate = $date ?? now()->toDateString();

            // قيد العكس يقلب المدين والدائن ويحافظ على subledger
            $reversalEntries = $transaction->entries->map(fn($entry) => [
                'account_id'     => $entry->account_id,
                'debit'          => $entry->credit,  // مقلوب
                'credit'         => $entry->debit,   // مقلوب
                'description'    => "عكس: " . ($entry->description ?? $transaction->description),
                'subledger_type' => $entry->subledger_type, // ← احتفظ بنوع الكيان
                'subledger_id'   => $entry->subledger_id,   // ← احتفظ بمعرف الكيان
            ])->toArray();

            $reversal = $this->createAndPost([
                'date'          => $reversalDate,
                'type'          => $transaction->type,
                'description'   => "عكس القيد {$transaction->transaction_number}: {$reason}",
                'branch_id'     => $transaction->branch_id,
                'reversal_of_id' => $transaction->id,
                'is_reversal'   => true,
                'source_type'   => $transaction->source_type,
                'source_id'     => $transaction->source_id,
                'prefix'        => 'RV',
            ], $reversalEntries);

            return $reversal;
        });
    }

    // ──────────────────────────────────────────────────────────────

    private function validateEntries(array $entries): void
    {
        if (count($entries) < 2) {
            throw new RuntimeException('القيد يتطلب سطرين على الأقل');
        }

        $totalDebit  = collect($entries)->sum('debit');
        $totalCredit = collect($entries)->sum('credit');

        if (abs($totalDebit - $totalCredit) > 0.001) {
            throw new RuntimeException(
                "القيد غير متوازن — مدين: {$totalDebit} / دائن: {$totalCredit}"
            );
        }

        foreach ($entries as $i => $entry) {
            $d = (float)($entry['debit'] ?? 0);
            $c = (float)($entry['credit'] ?? 0);

            if ($d > 0 && $c > 0) {
                throw new RuntimeException("السطر " . ($i + 1) . " لا يمكن أن يكون مديناً ودائناً في آن واحد");
            }
            if ($d == 0 && $c == 0) {
                throw new RuntimeException("السطر " . ($i + 1) . " المبلغ يجب أن يكون أكبر من صفر");
            }
        }
    }

    private function validatePeriod(string $date): void
    {
        // إذا لا توجد فترات محاسبية مُعرَّفة، نتجاوز التحقق
        if (! \Illuminate\Support\Facades\Schema::hasTable('accounting_periods')) {
            return;
        }

        $period = AccountingPeriod::where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->first();

        if ($period && ! $period->isOpen()) {
            throw new RuntimeException("الفترة المحاسبية مغلقة — لا يمكن إضافة قيود");
        }
    }

    private function findPeriodId(string $date): ?int
    {
        return AccountingPeriod::where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->value('id');
    }
}
