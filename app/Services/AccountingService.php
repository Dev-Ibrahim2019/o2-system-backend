<?php

namespace App\Services;

use App\Models\Entry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Services\Accounting\JournalEntryValidationService;
use App\Services\Accounting\SystemAccountProvisioner;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * خدمة القيود المحاسبية — إنشاء قيد يومية عند سداد فاتورة
 *
 * مثال بدون خصم: فاتورة 100، سداد 100 كاش
 * Dr صندوق الإيرادات النقدي (11101)         100
 * Cr إيرادات المبيعات (4110)                100
 *
 * مثال مع خصم: فاتورة 80 (بعد خصم 20)، سداد 80 كاش
 * Dr صندوق الإيرادات النقدي (11101)          80
 * Dr خصومات المبيعات (4120)                  20
 * Cr إيرادات المبيعات (4110)               100
 */
class AccountingService
{
    public function __construct(
        private readonly SystemAccountProvisioner $accountProvisioner,
        private readonly JournalEntryValidationService $journalValidator,
    ) {}

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

        $payments = $invoice->payments()->get();
        if ($payments->isEmpty()) {
            return null;
        }

        $revenueAccount = $this->accountProvisioner->ensureSalesRevenueAccount();
        $discountAmount = (float) $invoice->discount;

        if ($discountAmount > 0) {
            $this->accountProvisioner->ensureSalesDiscountsAccount();
        }

        return DB::transaction(function () use ($invoice, $payments, $revenueAccount, $total, $discountAmount) {
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

            $pendingEntries = [];
            $sortOrder = 1;

            foreach ($payments as $payment) {
                $entry = $this->buildPaymentDebitEntry($invoice, $payment, $sortOrder);
                $entry['transaction_id'] = $transaction->id;
                $pendingEntries[] = $entry;
                $sortOrder++;
            }

            if ($discountAmount > 0) {
                $salesDiscountsAccount = $this->accountProvisioner->ensureSalesDiscountsAccount();
                $pendingEntries[] = [
                    'transaction_id' => $transaction->id,
                    'account_id' => $salesDiscountsAccount->id,
                    'debit' => $discountAmount,
                    'credit' => 0,
                    'description' => "خصم مبيعات — فاتورة {$invoice->number}",
                    'sort_order' => $sortOrder,
                ];
                $sortOrder++;
            }

            $revenueAmount = round($total + $discountAmount, 3);
            $pendingEntries[] = [
                'transaction_id' => $transaction->id,
                'account_id' => $revenueAccount->id,
                'debit' => 0,
                'credit' => $revenueAmount,
                'description' => "إيرادات مبيعات — فاتورة {$invoice->number}",
                'sort_order' => $sortOrder,
            ];

            $this->journalValidator->assertBalanced(
                $pendingEntries,
                "فاتورة {$invoice->number}"
            );

            foreach ($pendingEntries as $entryData) {
                Entry::create($entryData);
            }

            $this->journalValidator->assertTransactionBalanced(
                $transaction->fresh(['entries'])
            );

            return $transaction;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPaymentDebitEntry(Invoice $invoice, Payment $payment, int $sortOrder): array
    {
        $methodType = $payment->method;
        if ($methodType === 'account') {
            $methodType = $payment->entity_type ?? $payment->subledger_type ?? 'customer';
        }

        $paymentMethod = PaymentMethod::with('account')
            ->where('type', $methodType)
            ->where('is_active', true)
            ->first();

        if (! $paymentMethod || ! $paymentMethod->account) {
            throw new RuntimeException(
                "طريقة الدفع '{$methodType}' غير معرّفة أو غير مفعلة للدفعة #{$payment->id}. " .
                'يرجى التأكد من تشغيل PaymentMethodSeeder.'
            );
        }

        $amount = (float) $payment->amount;
        $entityType = $payment->entity_type ?? $payment->subledger_type ?? null;
        $entityId = $payment->entity_id ?? $payment->subledger_id ?? null;
        $isEntityPayment = $entityType !== null && $entityId !== null;

        $entryData = [
            'account_id' => $paymentMethod->account->id,
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

        if ($isEntityPayment) {
            $entryData['subledger_type'] = $entityType;
            $entryData['subledger_id'] = $entityId;
        }

        return $entryData;
    }
}
