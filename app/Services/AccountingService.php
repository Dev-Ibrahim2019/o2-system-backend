<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Entry;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * خدمة القيود المحاسبية — إنشاء قيد يومية عند سداد فاتورة
 *
 * تم التحديث: استخدام PaymentMethod المرن بدلاً من الأكواد الثابتة.
 * كل طريقة دفع مرتبطة بحساب مالي عبر payment_methods.account_id.
 *
 * ملاحظة: للمدفوعات المختلطة، استخدم SettlementEngine.
 * هذه الخدمة للتوافق مع الفواتير ذات الدفعة الواحدة فقط.
 */
class AccountingService
{
    private const REVENUE_ACCOUNT_CODE = '4110';

    /**
     * إنشاء قيد محاسبي تلقائي عند سداد فاتورة بالكامل
     *
     * - للمدفوعات النقدية/البنك/البطاقة/المحفظة:
     *   debit  : حساب طريقة الدفع (من payment_methods.account_id)
     *   credit : حساب إيرادات المبيعات
     *
     * - للمدفوعات على حسابات الكيانات (موظف/عميل/مورد):
     *   debit  : حساب المراقبة للكيان (سلف موظفين/ذمم مدينة/ذمم دائنة)
     *   credit : حساب إيرادات المبيعات
     *   مع حفظ subledger_type و subledger_id في القيد
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

        // Check if there are multiple payments - if so, delegate to SettlementEngine
        $paymentCount = $invoice->payments()->count();
        if ($paymentCount > 1) {
            // Mixed payment - handled by SettlementEngine
            return null;
        }

        // Get the first payment with entity data
        $firstPayment = $invoice->payments()->first();

        // Get the payment method from the payment record or invoice
        // إذا method = 'account' (مدفوعات على حساب كيان)، نستخدم entity_type للبحث
        $methodType = $firstPayment?->method ?? $invoice->payment_method ?? 'cash';
        if ($methodType === 'account') {
            $methodType = $firstPayment?->entity_type ?? $firstPayment?->subledger_type ?? 'cash';
        }

        // ═══════════════════════════════════════════════════
        // [TRACE] القيم قبل البحث عن PaymentMethod — تسجل في storage/logs/laravel.log
        logger()->info('TRACE AccountingService::createJournalEntryForInvoice', [
            'method' => $firstPayment?->method ?? null,
            'entity_type' => $firstPayment?->entity_type ?? null,
            'entity_id' => $firstPayment?->entity_id ?? null,
            'subledger_type' => $firstPayment?->subledger_type ?? null,
            'subledger_id' => $firstPayment?->subledger_id ?? null,
            'methodType' => $methodType ?? null,
            'invoice_payment_method' => $invoice->payment_method ?? null,
            'invoice_status' => $invoice->status,
            'payment_count' => $paymentCount,
        ]);
        // ═══════════════════════════════════════════════════

        $paymentMethod = PaymentMethod::with('account')
            ->where('type', $methodType)
            ->where('is_active', true)
            ->first();

        if (! $paymentMethod || ! $paymentMethod->account) {
            throw new RuntimeException(
                "طريقة الدفع '{$methodType}' غير معرّفة أو غير مفعلة. " .
                    "يرجى التأكد من تشغيل PaymentMethodSeeder."
            );
        }

        // ── تحديد حساب الطرف المقابل (المدين) ──────────────────────────────
        // إذا كانت الدفعة على حساب كيان (موظف/عميل/مورد)،
        // نستخدم حساب المراقبة الخاص بالكيان من payment_methods.account_id
        // مع حفظ subledger_type و subledger_id في القيد.
        // أما إذا كانت دفعة نقدية/بنك/بطاقة، فنستخدم حساب الدفع المباشر.
        $debitAccount = $paymentMethod->account;
        $revenueAccount = $this->findRevenueAccount();

        if (! $revenueAccount) {
            return null;
        }

        // استخراج بيانات الكيان من الدفعة
        $entityType = $firstPayment?->entity_type ?? $firstPayment?->subledger_type ?? null;
        $entityId = $firstPayment?->entity_id ?? $firstPayment?->subledger_id ?? null;
        $isEntityPayment = $entityType !== null && $entityId !== null;

        return DB::transaction(function () use ($invoice, $debitAccount, $revenueAccount, $total, $firstPayment, $entityType, $entityId, $isEntityPayment) {
            $transaction = Transaction::create([
                'transaction_number' => Transaction::generateNumber(),
                'date' => now(),
                'reference' => $invoice->number,
                'type' => 'sale',
                'status' => 'posted',
                'description' => "قيد مبيعات — فاتورة {$invoice->number} / طلب {$invoice->order?->order_number}",
                'branch_id' => $invoice->branch_id,
                'source_type' => $invoice->order ? get_class($invoice->order) : null,
                'source_id' => $invoice->order_id,
                'posted_at' => now(),
            ]);

            // السطر الأول: الجانب المدين
            // للكيانات: حساب المراقبة (سلف موظفين/ذمم مدينة/ذمم دائنة) مع subledger
            // للنقد: حساب الصندوق/البنك/البطاقة
            $entryData = [
                'transaction_id' => $transaction->id,
                'account_id' => $debitAccount->id,
                'debit' => $total,
                'credit' => 0,
                'description' => $isEntityPayment
                    ? match ($entityType) {
                        'employee' => "سلفة موظف — فاتورة {$invoice->number}",
                        'customer' => "ذمم مدينة — فاتورة {$invoice->number}",
                        'supplier' => "ذمم دائنة — فاتورة {$invoice->number}",
                        default => "قبض — فاتورة {$invoice->number}",
                    }
                    : "قبض — فاتورة {$invoice->number}",
                'sort_order' => 1,
            ];

            // إذا كانت دفعة على حساب كيان، نضيف subledger
            if ($isEntityPayment) {
                $entryData['subledger_type'] = $entityType;
                $entryData['subledger_id'] = $entityId;
            }

            Entry::create($entryData);

            // السطر الثاني: الجانب الدائن — إيرادات المبيعات (بدون subledger)
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
     * جلب حساب إيرادات المبيعات
     */
    protected function findRevenueAccount(): ?Account
    {
        return Account::where('code', self::REVENUE_ACCOUNT_CODE)
            ->where('is_active', true)
            ->first();
    }
}
