<?php

namespace App\Services\SalesInvoice;

use App\Models\Invoice;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PosSalesSyncService
{
    public function __construct(
        protected SalesInvoiceService $invoiceService,
    ) {}

    /**
     * End-of-day sync: consolidate POS invoices into a batch sales invoice.
     *
     * Business Logic:
     * 1. Fetch all POS invoices for the given date and branch
     * 2. Group by payment method
     * 3. Create a summary "daily batch" sales invoice
     * 4. Mark as paid with split payment methods
     * 5. Create journal entries
     */
    public function syncEndOfDay(array $params, User $user): SalesInvoice
    {
        $branchId = $params['branch_id'];
        $date = $params['date'] ?? now()->toDateString();
        $posRegisterId = $params['pos_register_id'] ?? null;

        // ── Fetch POS invoices for the day ──
        $query = Invoice::where('branch_id', $branchId)
            ->whereDate('invoice_date', $date)
            ->whereNotIn('status', ['cancelled']);

        if ($posRegisterId) {
            $query->where('pos_register_id', $posRegisterId);
        }

        $posInvoices = $query->get();

        if ($posInvoices->isEmpty()) {
            throw new \RuntimeException('لا توجد فواتير بيعية لهذا اليوم');
        }

        // ── Aggregate by payment method ──
        $paymentSummary = [];
        $orderSummaries = [];

        foreach ($posInvoices as $posInvoice) {
            // Get payments for this POS invoice
            $payments = $posInvoice->payments()->get();
            foreach ($payments as $payment) {
                $method = $payment->method ?? 'cash';
                $paymentSummary[$method] = ($paymentSummary[$method] ?? 0) + (float) $payment->amount;
            }

            // Build order summary
            $orderSummaries[] = [
                'order_id' => $posInvoice->order_id,
                'subtotal' => (float) $posInvoice->subtotal,
                'tax_total' => (float) $posInvoice->tax_total,
                'discount' => (float) $posInvoice->discount,
                'total' => (float) $posInvoice->total,
                'payment_method' => $posInvoice->payment_method,
            ];
        }

        // ── Create batch sales invoice ──
        return $this->invoiceService->createPosBatch([
            'branch_id' => $branchId,
            'batch_date' => $date,
            'pos_register_id' => $posRegisterId,
            'orders' => $orderSummaries,
            'payment_summary' => $paymentSummary,
        ], $user);
    }

    /**
     * Sync a single POS invoice to a sales invoice.
     */
    public function syncSingleInvoice(int $posInvoiceId, User $user): ?SalesInvoice
    {
        $posInvoice = Invoice::with(['items', 'payments', 'customer', 'branch'])
            ->findOrFail($posInvoiceId);

        // Check if already synced
        $existing = SalesInvoice::where('source', 'pos_sync')
            ->where('batch_date', $posInvoice->invoice_date)
            ->whereJsonContains('notes', "POS Invoice #{$posInvoice->id}")
            ->first();

        if ($existing) {
            return null; // Already synced
        }

        return DB::transaction(function () use ($posInvoice, $user) {
            $invoice = SalesInvoice::create([
                'type' => 'tax_invoice',
                'tax_treatment' => 'exclusive',
                'customer_id' => $posInvoice->customer_id,
                'customer_name' => $posInvoice->customer?->name ?? $posInvoice->customer_name,
                'invoice_date' => $posInvoice->invoice_date,
                'currency' => $posInvoice->currency ?? 'ILS',
                'branch_id' => $posInvoice->branch_id,
                'source' => 'pos_sync',
                'pos_register_id' => $posInvoice->pos_register_id,
                'batch_date' => $posInvoice->invoice_date,
                'notes' => "تم النسخ من فاتورة بيعية رقم {$posInvoice->number} (POS Invoice #{$posInvoice->id})",
                'status' => 'paid',
                'subtotal' => $posInvoice->subtotal,
                'discount_total' => $posInvoice->discount,
                'tax_total' => $posInvoice->tax_total,
                'total' => $posInvoice->total,
            ]);

            // Copy items
            foreach ($posInvoice->items as $posItem) {
                $invoice->items()->create([
                    'item_id' => $posItem->item_id,
                    'item_name' => $posItem->item_name,
                    'description' => $posItem->description,
                    'quantity' => $posItem->quantity,
                    'unit_price' => $posItem->unit_price,
                    'discount' => $posItem->discount,
                    'tax_rate' => $posItem->tax_rate,
                    'tax_amount' => $posItem->tax_amount,
                    'total_before_tax' => $posItem->total_before_tax,
                    'total' => $posItem->total,
                ]);
            }

            // Copy payments
            foreach ($posInvoice->payments as $posPayment) {
                $invoice->payments()->create([
                    'method' => $posPayment->method,
                    'amount' => $posPayment->amount,
                    'reference_number' => $posPayment->reference_number,
                    'paid_at' => $posPayment->paid_at,
                ]);
            }

            $invoice->recalculateTotals();

            return $invoice;
        });
    }

    /**
     * Batch sync: sync all unsynced POS invoices for a date range.
     */
    public function batchSync(array $params, User $user): array
    {
        $branchId = $params['branch_id'];
        $fromDate = $params['from_date'] ?? now()->subDay()->toDateString();
        $toDate = $params['to_date'] ?? now()->toDateString();

        $posInvoices = Invoice::where('branch_id', $branchId)
            ->whereBetween('invoice_date', [$fromDate, $toDate])
            ->whereNotIn('status', ['cancelled'])
            ->get();

        $synced = 0;
        $skipped = 0;
        $errors = [];

        foreach ($posInvoices as $posInvoice) {
            try {
                $result = $this->syncSingleInvoice($posInvoice->id, $user);
                if ($result) {
                    $synced++;
                } else {
                    $skipped++;
                }
            } catch (\Exception $e) {
                $errors[] = "POS Invoice #{$posInvoice->id}: {$e->getMessage()}";
            }
        }

        return compact('synced', 'skipped', 'errors');
    }
}
