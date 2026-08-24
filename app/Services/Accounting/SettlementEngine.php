<?php

namespace App\Services\Accounting;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\AccountingService;
use App\Services\Invoice\InvoiceFromOrderService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Settlement & Payment Routing Engine — invoice creation, mixed payments, journal posting.
 */
class SettlementEngine
{
    public function __construct(
        private readonly InvoiceFromOrderService $invoiceFromOrderService,
        private readonly AccountingService $accountingService,
    ) {}

    /**
     * @param  array<int, array{payment_method_id: int, amount: float, reference_number?: string, entity_type?: string, entity_id?: int}>  $payments
     * @param  array{type: string, id: int, name: string}|null  $register  صندوق المبيعات الذي تُحصَّل عليه الدفعات — مطلوب دائماً
     * @return array{order: Order, invoice: Invoice, transaction: \App\Models\Transaction|null}
     */
    public function settle(Order $order, array $payments, ?array $register = null): array
    {
        if ($register === null) {
            throw new RuntimeException('لا يمكن إتمام عملية البيع بدون تحديد صندوق المبيعات.');
        }

        // طلبات الكول سنتر تُدفع قبل تقسيمها لتذاكر الأقسام (التذاكر تُنشأ بعد الدفع عند إرسالها للمطبخ)
        if ($order->source !== 'call_center') {
            if ($order->tickets()->exists() === false && $order->items()->exists()) {
                throw new RuntimeException('الطلب غير مقسّم للأقسام — نفّذ confirm أولاً.');
            }

            if ($order->status === 'pending' && $order->tickets()->exists()) {
                $order->update(['status' => 'confirmed']);
            }
        }

        return DB::transaction(function () use ($order, $payments, $register) {
            $invoice = $order->invoice;
            if (! $invoice) {
                $invoice = $this->invoiceFromOrderService->createFromOrder($order, [
                    'customer_id' => null,
                    'employee_id' => null,
                    'supplier_id' => null,
                    'notes' => $order->note,
                ], auth()->id());
            }

            $invoiceTotal = (float) $invoice->total;
            $payments = app(PaymentPlanValidator::class)->validate($invoiceTotal, $payments);
            $paymentTotal = round(collect($payments)->sum('amount'), 3);

            if (abs($paymentTotal - $invoiceTotal) > 0.01) {
                throw new RuntimeException(
                    "مجموع الدفعات ({$paymentTotal}) لا يساوي إجمالي الفاتورة ({$invoiceTotal})."
                );
            }

            foreach ($payments as $row) {
                $paymentMethod = PaymentMethod::findOrFail($row['payment_method_id']);
                $entityType = $row['entity_type'] ?? null;
                $entityId = $row['entity_id'] ?? null;

                if ($paymentMethod->is_entity && (! $entityType || ! $entityId)) {
                    throw new RuntimeException(
                        "طريقة الدفع «{$paymentMethod->name}» تتطلب تحديد الكيان (entity_type / entity_id)."
                    );
                }

                Payment::create([
                    'invoice_id' => $invoice->id,
                    'customer_id' => $order->customer_id,
                    'number' => Payment::generateNumber(),
                    'method' => $paymentMethod->type,
                    'payment_method_id' => $paymentMethod->id,
                    'amount' => (float) $row['amount'],
                    'paid_at' => now(),
                    'notes' => $row['reference_number'] ?? null,
                    'branch_id' => $order->branch_id,
                    'user_id' => auth()->id(),
                    'register_type' => $register['type'],
                    'register_id' => $register['id'],
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'subledger_type' => $entityType,
                    'subledger_id' => $entityId,
                ]);
            }

            $invoice->update([
                'status' => 'paid',
                'payment_method' => PaymentMethod::find($payments[0]['payment_method_id'])?->type,
            ]);

            $order->update(['status' => 'paid']);

            $transaction = $this->accountingService->createJournalEntryForInvoice($invoice->fresh());

            return [
                'order' => $order->fresh(['items', 'invoice.payments']),
                'invoice' => $invoice->fresh(['items', 'payments']),
                'transaction' => $transaction?->load(['entries.account']),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettlementDetails(Order $order): array
    {
        $invoice = $order->invoice;
        $transaction = $order->journalEntry();

        return [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'order_status' => $order->status,
            'subtotal' => (float) $order->subtotal,
            'discount_amount' => (float) $order->discount_amount,
            'total' => (float) $order->total,
            'invoice' => $invoice ? [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => $invoice->status,
                'subtotal' => (float) $invoice->subtotal,
                'discount' => (float) $invoice->discount,
                'total' => (float) $invoice->total,
                'paid_amount' => $invoice->paidAmount(),
                'remaining_amount' => $invoice->remainingAmount(),
            ] : null,
            'journal_entry' => $transaction ? [
                'id' => $transaction->id,
                'transaction_number' => $transaction->transaction_number,
                'type' => $transaction->type,
                'status' => $transaction->status,
            ] : null,
        ];
    }
}
