<?php

namespace App\Services\SalesInvoice;

use App\Models\Account;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\SalesInvoicePayment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalesInvoiceService
{
    public function __construct(
        protected SalesInvoiceJournalService $journalService,
    ) {}

    // ═══════════════════════════════════════════════════════
    //  CRUD
    // ═══════════════════════════════════════════════════════

    public function list(array $filters = [])
    {
        $query = SalesInvoice::with(['customer', 'branch'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }
        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('reference_number', 'like', "%{$search}%");
            });
        }
        if (! empty($filters['from_date'])) {
            $query->where('invoice_date', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $query->where('invoice_date', '<=', $filters['to_date']);
        }

        $perPage = $filters['per_page'] ?? 20;

        return $query->paginate($perPage);
    }

    public function find(int $id): SalesInvoice
    {
        return SalesInvoice::with(['items.account', 'items.item', 'items.branch', 'payments.paymentMethod', 'customer', 'branch', 'approvedByUser', 'transaction'])
            ->findOrFail($id);
    }

    public function create(array $data, User $user): SalesInvoice
    {
        return DB::transaction(function () use ($data, $user) {
            $invoice = SalesInvoice::create([
                'type' => $data['type'] ?? 'tax_invoice',
                'tax_treatment' => $data['tax_treatment'] ?? 'exclusive',
                'customer_id' => $data['customer_id'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'customer_vat_number' => $data['customer_vat_number'] ?? null,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? null,
                'supply_date' => $data['supply_date'] ?? null,
                'currency' => $data['currency'] ?? 'ILS',
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'branch_id' => $data['branch_id'],
                'reference_number' => $data['reference_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'source' => $data['source'] ?? 'manual',
                'status' => $data['status'] ?? 'draft',
            ]);

            // ── Create Items ──
            foreach ($data['items'] as $itemData) {
                $item = new SalesInvoiceItem($itemData);
                $item->recalculate($invoice->tax_treatment);
                $invoice->items()->save($item);
            }

            // ── Create Payments (if provided) ──
            if (! empty($data['payments'])) {
                foreach ($data['payments'] as $paymentData) {
                    $invoice->payments()->create($paymentData);
                }
            }

            $invoice->recalculateTotals();

            return $invoice->load(['items', 'payments', 'customer', 'branch']);
        });
    }

    public function update(int $id, array $data, User $user): SalesInvoice
    {
        $invoice = SalesInvoice::findOrFail($id);

        return DB::transaction(function () use ($invoice, $data, $user) {
            $invoice->update([
                'type' => $data['type'] ?? $invoice->type,
                'tax_treatment' => $data['tax_treatment'] ?? $invoice->tax_treatment,
                'customer_id' => $data['customer_id'] ?? $invoice->customer_id,
                'customer_name' => $data['customer_name'] ?? $invoice->customer_name,
                'customer_phone' => $data['customer_phone'] ?? $invoice->customer_phone,
                'customer_vat_number' => $data['customer_vat_number'] ?? $invoice->customer_vat_number,
                'invoice_date' => $data['invoice_date'] ?? $invoice->invoice_date,
                'due_date' => $data['due_date'] ?? $invoice->due_date,
                'supply_date' => $data['supply_date'] ?? $invoice->supply_date,
                'currency' => $data['currency'] ?? $invoice->currency,
                'exchange_rate' => $data['exchange_rate'] ?? $invoice->exchange_rate,
                'branch_id' => $data['branch_id'] ?? $invoice->branch_id,
                'reference_number' => $data['reference_number'] ?? $invoice->reference_number,
                'notes' => $data['notes'] ?? $invoice->notes,
            ]);

            // ── Sync Items ──
            if (isset($data['items'])) {
                // Delete removed items
                $incomingIds = collect($data['items'])->pluck('id')->filter()->toArray();
                $invoice->items()->whereNotIn('id', $incomingIds)->delete();

                foreach ($data['items'] as $itemData) {
                    if (! empty($itemData['id'])) {
                        // Update existing
                        $item = SalesInvoiceItem::findOrFail($itemData['id']);
                        $item->fill($itemData);
                        $item->recalculate($invoice->tax_treatment);
                        $item->save();
                    } else {
                        // Create new
                        $item = new SalesInvoiceItem($itemData);
                        $item->recalculate($invoice->tax_treatment);
                        $invoice->items()->save($item);
                    }
                }
            }

            // ── Sync Payments ──
            if (isset($data['payments'])) {
                $incomingPaymentIds = collect($data['payments'])->pluck('id')->filter()->toArray();
                $invoice->payments()->whereNotIn('id', $incomingPaymentIds)->delete();

                foreach ($data['payments'] as $paymentData) {
                    if (! empty($paymentData['id'])) {
                        $payment = SalesInvoicePayment::findOrFail($paymentData['id']);
                        $payment->update($paymentData);
                    } else {
                        $invoice->payments()->create($paymentData);
                    }
                }
            }

            $invoice->recalculateTotals();

            return $invoice->load(['items', 'payments', 'customer', 'branch']);
        });
    }

    public function delete(int $id): void
    {
        $invoice = SalesInvoice::findOrFail($id);

        if ($invoice->status !== 'draft') {
            throw new \RuntimeException('لا يمكن حذف فاتورة غير مسودة');
        }

        DB::transaction(function () use ($invoice) {
            $invoice->items()->delete();
            $invoice->payments()->delete();
            $invoice->delete();
        });
    }

    // ═══════════════════════════════════════════════════════
    //  Workflow Actions
    // ═══════════════════════════════════════════════════════

    /**
     * Approve/authorize a draft invoice.
     * Changes status to awaiting_payment and creates double-entry journal.
     */
    public function approve(int $id, User $user): SalesInvoice
    {
        $invoice = SalesInvoice::with(['items.account', 'customer'])->findOrFail($id);

        if (! in_array($invoice->status, ['draft', 'awaiting_approval'])) {
            throw new \RuntimeException('لا يمكن تعميد فاتورة بحالة ' . $invoice->status);
        }

        return DB::transaction(function () use ($invoice, $user) {
            // Create journal entry
            $transaction = $this->journalService->postSalesInvoice($invoice, $user);

            $invoice->update([
                'status' => 'awaiting_payment',
                'approved_by' => $user->id,
                'approved_at' => now(),
                'transaction_id' => $transaction->id,
            ]);

            return $invoice->load(['items', 'payments', 'customer', 'branch', 'transaction']);
        });
    }

    /**
     * Cancel an invoice.
     */
    public function cancel(int $id, User $user): SalesInvoice
    {
        $invoice = SalesInvoice::findOrFail($id);

        if (in_array($invoice->status, ['paid', 'cancelled'])) {
            throw new \RuntimeException('لا يمكن إلغاء فاتورة مدفوعة أو ملغاة');
        }

        // If approved, reverse the journal entry
        if ($invoice->transaction_id && in_array($invoice->status, ['awaiting_payment', 'awaiting_approval'])) {
            DB::transaction(function () use ($invoice, $user) {
                $this->journalService->reverseTransaction($invoice->transaction_id, $user);
                $invoice->cancel();
            });
        } else {
            $invoice->cancel();
        }

        return $invoice->fresh();
    }

    /**
     * Record a payment against an invoice.
     */
    public function recordPayment(int $id, array $paymentData, User $user): SalesInvoice
    {
        $invoice = SalesInvoice::findOrFail($id);

        if (! in_array($invoice->status, ['awaiting_payment', 'partial'])) {
            throw new \RuntimeException('لا يمكن تسجيل دفعة على فاتورة بحالة ' . $invoice->status);
        }

        return DB::transaction(function () use ($invoice, $paymentData, $user) {
            // Set paid_at if not provided
            $paymentData['paid_at'] = $paymentData['paid_at'] ?? now();

            $invoice->payments()->create($paymentData);
            $invoice->recalculateTotals();

            // If fully paid, mark as paid
            if ($invoice->remaining_amount <= 0.0001) {
                $invoice->markPaid();
            } elseif ($invoice->paid_amount > 0) {
                $invoice->update(['status' => 'partial']);
            }

            return $invoice->fresh(['items', 'payments', 'customer', 'branch']);
        });
    }

    // ═══════════════════════════════════════════════════════
    //  POS Sync
    // ═══════════════════════════════════════════════════════

    /**
     * Create a batch of sales invoices from POS end-of-day data.
     * Groups by payment method and creates a summary invoice.
     */
    public function createPosBatch(array $posData, User $user): SalesInvoice
    {
        return DB::transaction(function () use ($posData, $user) {
            $branchId = $posData['branch_id'];
            $batchDate = $posData['batch_date'] ?? now()->toDateString();
            $orders = $posData['orders'] ?? [];
            $paymentSummary = $posData['payment_summary'] ?? [];

            // Calculate totals from orders
            $subtotal = collect($orders)->sum('subtotal');
            $taxTotal = collect($orders)->sum('tax_total');
            $discountTotal = collect($orders)->sum('discount');
            $total = collect($orders)->sum('total');

            // Create summary invoice
            $invoice = SalesInvoice::create([
                'type' => 'tax_invoice',
                'tax_treatment' => 'exclusive',
                'customer_name' => 'مبيعات يوم ' . $batchDate,
                'invoice_date' => $batchDate,
                'supply_date' => $batchDate,
                'currency' => 'ILS',
                'branch_id' => $branchId,
                'source' => 'pos_sync',
                'batch_date' => $batchDate,
                'pos_register_id' => $posData['pos_register_id'] ?? null,
                'notes' => 'فاتورة مجموع مبيعات يوم ' . $batchDate,
                'status' => 'paid',
            ]);

            // Create line item per payment method
            foreach ($paymentSummary as $method => $amount) {
                if ($amount <= 0) {
                    continue;
                }
                $invoice->items()->create([
                    'item_name' => 'مبيعات ' . $this->getMethodLabel($method),
                    'description' => 'مجموع مبيعات ' . $this->getMethodLabel($method) . ' - يوم ' . $batchDate,
                    'quantity' => 1,
                    'unit_price' => $amount,
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                    'total_before_tax' => $amount,
                    'total' => $amount,
                ]);
            }

            // Create payment records
            foreach ($paymentSummary as $method => $amount) {
                if ($amount <= 0) {
                    continue;
                }
                $invoice->payments()->create([
                    'method' => $method,
                    'amount' => $amount,
                    'paid_at' => $batchDate,
                    'notes' => 'دفعة ' . $this->getMethodLabel($method),
                ]);
            }

            $invoice->recalculateTotals();

            // Create journal entry for the batch
            $this->journalService->postSalesInvoice($invoice, $user);

            return $invoice->load(['items', 'payments', 'branch']);
        });
    }

    // ═══════════════════════════════════════════════════════
    //  Stats
    // ═══════════════════════════════════════════════════════

    public function getStats(array $filters = []): array
    {
        $query = SalesInvoice::query();

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        $total = (clone $query)->count();
        $draft = (clone $query)->where('status', 'draft')->count();
        $awaitingApproval = (clone $query)->where('status', 'awaiting_approval')->count();
        $awaitingPayment = (clone $query)->where('status', 'awaiting_payment')->count();
        $paid = (clone $query)->where('status', 'paid')->count();
        $cancelled = (clone $query)->where('status', 'cancelled')->count();

        $totalAmount = (clone $query)->sum('total');
        $paidAmount = (clone $query)->sum('paid_amount');
        $remainingAmount = (clone $query)->sum('remaining_amount');
        $overdueAmount = (clone $query)->where('status', 'awaiting_payment')
            ->where('due_date', '<', now())
            ->sum('remaining_amount');

        return compact(
            'total', 'draft', 'awaitingApproval', 'awaitingPayment', 'paid', 'cancelled',
            'totalAmount', 'paidAmount', 'remainingAmount', 'overdueAmount'
        );
    }

    // ═══════════════════════════════════════════════════════
    //  Helpers
    // ═══════════════════════════════════════════════════════

    protected function getMethodLabel(string $method): string
    {
        return match ($method) {
            'cash' => 'نقدي',
            'credit_card' => 'بطاقة ائتمان',
            'bank_transfer' => 'تحويل بنكي',
            'app' => 'تطبيق دفع',
            'account' => 'حساب مفتوح',
            default => $method,
        };
    }
}
