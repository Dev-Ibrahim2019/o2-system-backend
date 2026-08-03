<?php

namespace App\Services\Invoice;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\AccountingService;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class InvoicePaymentService
{
    public function __construct(private readonly AccountingService $accountingService) {}

    /** @return array{payment: Payment, invoice: Invoice, transaction: \App\Models\Transaction|null} */
    public function recordInvoicePayment(
        Invoice $invoice,
        string $method,
        ?int $paymentMethodId,
        float $amount,
        ?int $executedBy,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $referenceNumber = null,
        ?string $notes = null,
        ?int $branchId = null,
    ): array {
        $remaining = $invoice->fresh()->remainingAmount();
        if ($amount <= 0 || $amount > $remaining + 0.001) {
            throw new UnprocessableEntityHttpException("يجب أن يكون مبلغ الدفع موجبًا وألا يتجاوز المتبقي ({$remaining}).");
        }

        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'number' => Payment::generateNumber(),
            'method' => $method,
            'payment_method_id' => $paymentMethodId,
            'amount' => $amount,
            'reference_number' => $referenceNumber,
            'paid_at' => now(),
            'notes' => $notes,
            'branch_id' => $branchId ?? $invoice->branch_id,
            'user_id' => $executedBy,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'subledger_type' => $entityType,
            'subledger_id' => $entityId,
        ]);

        $invoice = $invoice->fresh();
        $fullyPaid = $invoice->paidAmount() >= (float) $invoice->total - 0.001;
        $invoice->update([
            'status' => $fullyPaid ? 'paid' : 'partial',
            'payment_method' => $method,
            'closed_by' => $fullyPaid ? $executedBy : null,
            'closed_at' => $fullyPaid ? now() : null,
        ]);

        $invoice = $invoice->fresh();
        $transaction = $fullyPaid
            ? $this->accountingService->createJournalEntryForInvoice($invoice)
            : null;

        return compact('payment', 'invoice', 'transaction');
    }
}
