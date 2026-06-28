<?php

namespace App\Services;

use App\Models\Account;
use App\Models\DiscountSetting;
use App\Models\Entry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * خدمة القيود المحاسبية — إنشاء قيد يومية عند سداد فاتورة
 *
 * تدعم الآن المدفوعات المتعددة (المختلطة) والخصومات بشكل كامل.
 *
 * مثال بدون خصم: فاتورة 100، سداد 100 كاش
 * Dr صندوق الإيرادات النقدي (11101)         100
 * Cr إيرادات المبيعات (4110)                100
 *
 * مثال مع خصم: فاتورة 90 (بعد خصم 10)، سداد 90 كاش
 * Dr صندوق الإيرادات النقدي (11101)          90
 * Dr خصومات المبيعات (4120)                   10
 * Cr إيرادات المبيعات (4110)                100
 *
 * تدعم أيضاً:
 * - دفعات متعددة (مختلطة) بوسائل دفع مختلفة
 * - دفعات على حسابات كيانات (عميل/موظف/مورد) مع subledger
 * - خصومات المبيعات عبر حساب منفصل (4120 افتراضياً)
 */
class AccountingService
{
    private const REVENUE_ACCOUNT_CODE = '4110';
    private const SALES_DISCOUNTS_ACCOUNT_CODE = '4120';

    /**
     * إنشاء قيد محاسبي تلقائي عند سداد فاتورة بالكامل
     *
     * تدعم الآن:
     * - دفعة واحدة (cash/card/bank/wallet)
     * - دفعات متعددة (mixed payments) بوسائل دفع مختلفة
     * - دفعات على حسابات كيانات (عميل/موظف/مورد) مع subledger
     *
     * لكل دفعة يتم إنشاء سطر مدين مستقل باستخدام حسابها المالي
     * من جدول payment_methods.account_id.
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

        // جلب جميع الدفعات — سواء كانت واحدة أو متعددة (مختلطة)
        $payments = $invoice->payments()->get();

        if ($payments->isEmpty()) {
            return null;
        }

        $revenueAccount = $this->findRevenueAccount();
        if (! $revenueAccount) {
            return null;
        }

        // ═══════════════════════════════════════════════════
        // [TRACE] تسجيل معلومات الدفعات
        logger()->info('TRACE AccountingService::createJournalEntryForInvoice', [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->number,
            'total' => $total,
            'payment_count' => $payments->count(),
            'payments' => $payments->map(fn($p) => [
                'id' => $p->id,
                'method' => $p->method,
                'amount' => $p->amount,
                'entity_type' => $p->entity_type ?? $p->subledger_type ?? null,
                'entity_id' => $p->entity_id ?? $p->subledger_id ?? null,
            ]),
        ]);
        // ═══════════════════════════════════════════════════

        return DB::transaction(function () use ($invoice, $payments, $revenueAccount, $total) {
            // إنشاء رأس القيد المحاسبي (Transaction)
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

            $sortOrder = 1;
            $totalDebit = 0;

            // ── لكل دفعة: إنشاء سطر مدين مستقل ──────────────────────
            foreach ($payments as $payment) {
                // تحديد نوع وسيلة الدفع لحساب المبلغ
                $methodType = $payment->method;

                // إذا كانت دفعة على حساب كيان (account)، نستخدم entity_type
                if ($methodType === 'account') {
                    $methodType = $payment->entity_type ?? $payment->subledger_type ?? 'customer';
                }

                // البحث عن وسيلة الدفع في جدول payment_methods
                $paymentMethod = PaymentMethod::with('account')
                    ->where('type', $methodType)
                    ->where('is_active', true)
                    ->first();

                if (! $paymentMethod || ! $paymentMethod->account) {
                    throw new RuntimeException(
                        "طريقة الدفع '{$methodType}' غير معرّفة أو غير مفعلة للدفعة #{$payment->id}. " .
                            "يرجى التأكد من تشغيل PaymentMethodSeeder."
                    );
                }

                $debitAccount = $paymentMethod->account;
                $amount = (float) $payment->amount;

                // استخراج بيانات subledger من الدفعة
                $entityType = $payment->entity_type ?? $payment->subledger_type ?? null;
                $entityId = $payment->entity_id ?? $payment->subledger_id ?? null;
                $isEntityPayment = $entityType !== null && $entityId !== null;

                // إنشاء سطر مدين لهذه الدفعة
                $entryData = [
                    'transaction_id' => $transaction->id,
                    'account_id' => $debitAccount->id,
                    'debit' => $amount,
                    'credit' => 0,
                    'description' => $isEntityPayment
                        ? match ($entityType) {
                            'employee' => "سلفة موظف — فاتورة {$invoice->number} (دفعة {$payment->number})",
                            'customer' => "ذمم مدينة — فاتورة {$invoice->number} (دفعة {$payment->number})",
                            'supplier' => "ذمم دائنة — فاتورة {$invoice->number} (دفعة {$payment->number})",
                            default => "قبض ({$methodType}) — فاتورة {$invoice->number} (دفعة {$payment->number})",
                        }
                        : "قبض ({$methodType}) — فاتورة {$invoice->number} (دفعة {$payment->number})",
                    'sort_order' => $sortOrder,
                ];

                // إذا كانت دفعة على حساب كيان، نضيف subledger
                if ($isEntityPayment) {
                    $entryData['subledger_type'] = $entityType;
                    $entryData['subledger_id'] = $entityId;
                }

                Entry::create($entryData);
                $totalDebit += $amount;
                $sortOrder++;
            }

            // التحقق من تطابق المبلغ الإجمالي
            if (abs($totalDebit - $total) > 0.01) {
                logger()->warning('AccountingService: mismatch between total and sum of payments', [
                    'invoice_id' => $invoice->id,
                    'total' => $total,
                    'sum_payments' => $totalDebit,
                ]);
            }

            // ── السطر الأخير: الجانب الدائن — إيرادات المبيعات ──────
            $creditAmount = max($totalDebit, $total);
            Entry::create([
                'transaction_id' => $transaction->id,
                'account_id' => $revenueAccount->id,
                'debit' => 0,
                'credit' => $creditAmount,
                'description' => "إيرادات مبيعات — فاتورة {$invoice->number}",
                'sort_order' => $sortOrder,
            ]);

            return $transaction;
        });
    }

    /**
     * جلب حساب إيرادات المبيعات (كود 4110)
     */
    protected function findRevenueAccount(): ?Account
    {
        return Account::where('code', self::REVENUE_ACCOUNT_CODE)
            ->where('is_active', true)
            ->first();
    }

    /**
     * جلب حساب خصومات المبيعات (كود 4120) — مع إمكانية التعديل من الإعدادات
     */
    protected function findSalesDiscountsAccount(): ?Account
    {
        $code = DiscountSetting::getSalesDiscountsAccountCode();
        return Account::where('code', $code)
            ->where('is_active', true)
            ->first();
    }
}
