<?php

namespace App\Services\Accounting;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\AccountingService;
use App\Services\CallCenter\CustomerResolutionService;
use App\Services\CustomerIdentityService;
use App\Services\Invoice\InvoiceFromOrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Settlement & Payment Routing Engine — invoice creation, mixed payments, journal posting.
 */
class SettlementEngine
{
    public function __construct(
        private readonly InvoiceFromOrderService $invoiceFromOrderService,
        private readonly AccountingService $accountingService,
        private readonly CustomerResolutionService $customerResolution,
        private readonly CustomerIdentityService $customerIdentity,
    ) {}

    /**
     * @param  array<int, array{payment_method_id: int, amount: float, reference_number?: string, entity_type?: string, entity_id?: int}>  $payments
     * @return array{order: Order, invoice: Invoice, transaction: \App\Models\Transaction|null}
     */
    public function settle(Order $order, array $payments): array
    {
        if ($order->tickets()->exists() === false && $order->items()->exists()) {
            throw new RuntimeException('الطلب غير مقسّم للأقسام — نفّذ confirm أولاً.');
        }

        if ($order->status === 'pending' && $order->tickets()->exists()) {
            $order->update(['status' => 'confirmed']);
        }

        // If the order carries walk-in customer_name/customer_phone (typed by
        // the cashier at creation) but was never linked to a real Customer —
        // POS order creation only links customer_id when one was explicitly
        // picked, it never resolves from the typed phone — resolve or create
        // one now, using the exact same services Call Center already relies
        // on for this. Best-effort: a failure here must never block payment.
        if (! $order->customer_id && $order->customer_phone) {
            $this->linkOrCreateCustomer($order);
        }

        return DB::transaction(function () use ($order, $payments) {
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
                    'number' => Payment::generateNumber(),
                    'method' => $paymentMethod->type,
                    'payment_method_id' => $paymentMethod->id,
                    'amount' => (float) $row['amount'],
                    'paid_at' => now(),
                    'notes' => $row['reference_number'] ?? null,
                    'branch_id' => $order->branch_id,
                    'user_id' => auth()->id(),
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
     * Resolves $order->customer_phone against the existing customer base
     * (same phone-normalization lookup Call Center already uses) and links
     * it, or creates a new operational Customer if genuinely not found.
     * Never throws — a linking problem must not block a payment that's
     * otherwise valid.
     */
    private function linkOrCreateCustomer(Order $order): void
    {
        try {
            $resolved = $this->customerResolution->resolve($order->customer_phone);

            if ($resolved['status'] === 'found') {
                $order->update(['customer_id' => $resolved['customer']->id]);

                return;
            }

            if ($resolved['status'] === 'multiple') {
                // Ambiguous — more than one existing customer matches this
                // phone. Guessing which one is wrong; leave unlinked rather
                // than risk attaching the order to the wrong person.
                return;
            }

            $customer = $this->customerIdentity->create([
                'name' => $order->customer_name ?: ('عميل ' . $order->customer_phone),
                'phone' => $order->customer_phone,
                'branch_id' => $order->branch_id,
                // Auto-created from an in-person POS/dine-in settlement, not
                // through the CRM form, Call Center, or the website — "walk_in"
                // (حضور مباشر) is the existing CUSTOMER_SOURCE_VALUES entry
                // that matches this, not a new value.
                'source' => 'walk_in',
            ], Customer::TYPE_OPERATIONAL);

            $order->update(['customer_id' => $customer->id]);
        } catch (\Throwable $e) {
            Log::warning('SettlementEngine: تعذر ربط/إنشاء العميل أثناء التسوية.', [
                'order_id' => $order->id,
                'exception' => $e->getMessage(),
            ]);
        }
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
