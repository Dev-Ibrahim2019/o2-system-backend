<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\Order;
use App\Models\Invoice;
use App\Models\Entry;
use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceDetailsController extends ApiController
{
    /**
     * GET /api/invoices/{invoice}/details
     * Overview + Header
     */
    public function details(Invoice $invoice): JsonResponse
    {
        $invoice->loadMissing(['order.cashier', 'order.items.department', 'branch', 'items', 'payments']);
        $order = $invoice->order;

        $data = [
            'id'               => $invoice->id,
            'number'           => $invoice->number,
            'order_number'     => $order?->order_number,
            'status'           => $invoice->status,
            'order_status'     => $order?->status,
            'order_type'       => $order?->order_type,
            'branch_id'        => $invoice->branch_id,
            'branch_name'      => $invoice->relationLoaded('branch') && $invoice->branch ? $invoice->branch->name : null,
            'customer_name'    => $order?->customer_name ?? $invoice->customer?->name,
            'customer_phone'   => $order?->customer_phone,
            'table_number'     => $order?->table_number,
            'cashier_name'     => $order?->relationLoaded('cashier') && $order?->cashier ? $order->cashier->name : null,
            'cashier_id'       => $order?->cashier_id,
            'created_at'       => $invoice->created_at?->toIso8601String(),
            'invoice_date'     => $invoice->invoice_date?->toIso8601String(),
            'paid_at'          => $order?->paid_at,
            'subtotal'         => (float) ($invoice->subtotal ?? 0),
            'discount'         => (float) ($invoice->discount ?? 0),
            'total'            => (float) ($invoice->total ?? 0),
            'paid_amount'      => (float) $invoice->paidAmount(),
            'remaining_amount' => (float) $invoice->remainingAmount(),
            'notes'            => $invoice->notes,
            'payment_method'   => $invoice->payment_method,
        ];

        // Discount info from first item with discount
        $firstDiscountedItem = $invoice->items->first(fn($i) => $i->discount_amount > 0 || $i->discount_percent > 0);
        $data['discount_info'] = $firstDiscountedItem ? [
            'has_discount'       => true,
            'discount_type'      => $firstDiscountedItem->discount_percent > 0 ? 'percent' : 'amount',
            'discount_value'     => (float) ($firstDiscountedItem->discount_percent ?: $firstDiscountedItem->discount_amount),
            'discount_apply_strategy' => $firstDiscountedItem->discount_apply_strategy,
        ] : ['has_discount' => false];

        return $this->success('تفاصيل الفاتورة', $data);
    }

    /**
     * GET /api/invoices/{invoice}/products
     */
    public function products(Invoice $invoice): JsonResponse
    {
        $invoice->loadMissing(['items.discountDetail']);
        $items = $invoice->items->map(fn($item) => [
            'id'                => $item->id,
            'item_id'           => $item->item_id,
            'item_name'         => $item->item_name,
            'quantity'          => (float) $item->quantity,
            'unit_price'        => (float) $item->price,
            'original_price'    => (float) ($item->original_price ?? $item->price),
            'final_price'       => (float) ($item->final_price ?? $item->total),
            'subtotal'          => (float) ($item->subtotal ?? ($item->price * $item->quantity)),
            'total'             => (float) $item->total,
            'discount_amount'   => (float) ($item->discount_amount ?? 0),
            'discount_percent'  => (float) ($item->discount_percent ?? 0),
            'discount_id'       => $item->discount_id,
            'discount_apply_strategy' => $item->discount_apply_strategy,
            'discount_name'     => $item->relationLoaded('discountDetail') && $item->discountDetail ? $item->discountDetail->name : null,
            'tax_rate'          => (float) ($item->tax_rate ?? 0),
            'tax_amount'        => (float) ($item->tax_amount ?? 0),
            'notes'             => null,
        ]);

        return $this->success('منتجات الفاتورة', [
            'items'        => $items,
            'total_items'  => $items->sum('quantity'),
            'subtotal'     => $items->sum('subtotal'),
            'total_discount' => $items->sum('discount_amount'),
            'grand_total'  => $items->sum('total'),
            'tax_total'    => $items->sum('tax_amount'),
        ]);
    }

    /**
     * GET /api/invoices/{invoice}/payments
     */
    public function payments(Invoice $invoice): JsonResponse
    {
        $payments = $invoice->payments()->with(['user', 'branch'])->get()->map(fn($p) => [
            'id'               => $p->id,
            'number'           => $p->number,
            'method'           => $p->method,
            'amount'           => (float) $p->amount,
            'reference_number' => $p->reference_number,
            'paid_at'          => $p->paid_at?->toIso8601String(),
            'user_name'        => $p->relationLoaded('user') && $p->user ? $p->user->name : null,
            'branch_name'      => $p->relationLoaded('branch') && $p->branch ? $p->branch->name : null,
            'notes'            => $p->notes,
            'entity_type'      => $p->entity_type,
            'entity_id'        => $p->entity_id,
        ]);

        return $this->success('دفعات الفاتورة', [
            'payments'      => $payments,
            'total_paid'    => $payments->sum('amount'),
            'payment_count' => $payments->count(),
        ]);
    }

    /**
     * GET /api/invoices/{invoice}/accounting
     */
    public function accounting(Invoice $invoice): JsonResponse
    {
        $order = $invoice->order;
        if (!$order) {
            return $this->success('القيود المحاسبية', ['entries' => []]);
        }

        $transaction = Transaction::where('source_type', Order::class)
            ->where('source_id', $order->id)
            ->where('type', 'sale')
            ->with(['entries.account', 'entries.costCenter', 'user', 'branch'])
            ->first();

        if (!$transaction) {
            return $this->success('القيود المحاسبية', ['entries' => []]);
        }

        $entries = $transaction->entries->map(fn($e) => [
            'id'               => $e->id,
            'account_id'       => $e->account_id,
            'account_code'     => $e->relationLoaded('account') && $e->account ? $e->account->code : null,
            'account_name'     => $e->relationLoaded('account') && $e->account ? $e->account->name : null,
            'debit'            => (float) $e->debit,
            'credit'           => (float) $e->credit,
            'description'      => $e->description,
            'cost_center_name' => $e->relationLoaded('costCenter') && $e->costCenter ? $e->costCenter->name : null,
            'subledger_type'   => $e->subledger_type,
            'subledger_id'     => $e->subledger_id,
        ]);

        return $this->success('القيود المحاسبية', [
            'entries'          => $entries,
            'transaction_id'   => $transaction->id,
            'transaction_number' => $transaction->transaction_number,
            'transaction_date' => $transaction->date?->toDateString(),
            'status'           => $transaction->status,
            'user_name'        => $transaction->relationLoaded('user') && $transaction->user ? $transaction->user->name : null,
            'branch_name'      => $transaction->relationLoaded('branch') && $transaction->branch ? $transaction->branch->name : null,
            'posted_at'        => $transaction->posted_at?->toIso8601String(),
            'total_debit'      => $entries->sum('debit'),
            'total_credit'     => $entries->sum('credit'),
        ]);
    }

    /**
     * GET /api/invoices/{invoice}/discounts
     */
    public function discounts(Invoice $invoice): JsonResponse
    {
        $invoice->loadMissing(['items' => fn($q) => $q->where('discount_amount', '>', 0)->orWhere('discount_percent', '>', 0), 'items.discountDetail']);

        $discounts = $invoice->items->map(fn($item) => [
            'item_name'              => $item->item_name,
            'item_id'                => $item->item_id,
            'quantity'               => (float) $item->quantity,
            'unit_price'             => (float) $item->price,
            'original_total'         => (float) ($item->quantity * $item->price),
            'subtotal'               => (float) ($item->subtotal ?? ($item->quantity * $item->price)),
            'total'                  => (float) $item->total,
            'discount_amount'        => (float) ($item->discount_amount ?? 0),
            'discount_percent'       => (float) ($item->discount_percent ?? 0),
            'discount_apply_strategy'=> $item->discount_apply_strategy,
            'discount_name'          => $item->relationLoaded('discountDetail') && $item->discountDetail ? $item->discountDetail->name : null,
            'discount_id'            => $item->discount_id,
        ])->filter(fn($d) => $d['discount_amount'] > 0 || $d['discount_percent'] > 0)->values();

        $totalDiscount = $discounts->sum('discount_amount');
        $invoiceDiscount = (float) ($invoice->discount ?? 0);

        return $this->success('خصومات الفاتورة', [
            'discounts'          => $discounts,
            'total_item_discount'=> round($totalDiscount, 3),
            'total_invoice_discount' => round(max(0, $invoiceDiscount - $totalDiscount), 3),
            'total_discount'     => round($invoiceDiscount, 3),
            'discount_count'     => $discounts->count(),
        ]);
    }

    /**
     * GET /api/invoices/{invoice}/inventory
     */
    public function inventory(Invoice $invoice): JsonResponse
    {
        $invoice->loadMissing(['items']);
        $movements = $invoice->items->map(fn($item) => [
            'item_id'     => $item->item_id,
            'item_name'   => $item->item_name,
            'quantity'    => (float) $item->quantity,
            'unit_price'  => (float) $item->price,
            'total_cost'  => (float) $item->total,
            'movement'    => 'out',
            'type'        => 'sale',
        ]);

        return $this->success('حركة المخزون', [
            'movements' => $movements,
            'total_qty' => $movements->sum('quantity'),
        ]);
    }

    /**
     * GET /api/invoices/{invoice}/timeline
     */
    public function timeline(Invoice $invoice): JsonResponse
    {
        $invoice->loadMissing(['order']);
        $order = $invoice->order;
        $events = [];

        if ($invoice->created_at) {
            $events[] = ['event' => 'created', 'label' => 'إنشاء الفاتورة', 'timestamp' => $invoice->created_at->toIso8601String(), 'user' => null];
        }

        if ($order && $order->paid_at) {
            $events[] = ['event' => 'paid', 'label' => 'الدفع', 'timestamp' => $order->paid_at, 'user' => null];
        }

        // Check for journal entry
        $transaction = $order ? Transaction::where('source_type', Order::class)
            ->where('source_id', $order->id)
            ->where('type', 'sale')
            ->first() : null;

        if ($transaction) {
            if ($transaction->posted_at) {
                $events[] = ['event' => 'posted', 'label' => 'الترحيل المحاسبي', 'timestamp' => $transaction->posted_at->toIso8601String(), 'user' => $transaction->relationLoaded('user') && $transaction->user ? $transaction->user->name : null];
            }
        }

        $events[] = ['event' => 'status_' . $invoice->status, 'label' => "الحالة: {$invoice->status}", 'timestamp' => $invoice->updated_at?->toIso8601String(), 'user' => null];

        usort($events, fn($a, $b) => strcmp($a['timestamp'] ?? '', $b['timestamp'] ?? ''));

        return $this->success('الجدول الزمني', ['events' => $events]);
    }

    /**
     * GET /api/invoices/{invoice}/attachments
     */
    public function attachments(Invoice $invoice): JsonResponse
    {
        return $this->success('مرفقات الفاتورة', ['attachments' => []]);
    }

    /**
     * GET /api/invoices/{invoice}/notes
     */
    public function notes(Invoice $invoice): JsonResponse
    {
        $invoice->loadMissing(['order']);
        $allNotes = [];

        if ($invoice->notes) {
            $allNotes[] = ['source' => 'فاتورة', 'note' => $invoice->notes, 'created_at' => $invoice->created_at?->toIso8601String()];
        }

        $order = $invoice->order;
        if ($order && $order->note) {
            $allNotes[] = ['source' => 'طلب', 'note' => $order->note, 'created_at' => $order->created_at?->toIso8601String()];
        }

        return $this->success('ملاحظات', ['notes' => $allNotes]);
    }

    /**
     * GET /api/orders/{order}/invoice-id
     * Returns the invoice ID for a given order.
     */
    public function getInvoiceIdFromOrder(Order $order): JsonResponse
    {
        $invoice = $order->invoice;

        if (!$invoice) {
            return $this->success('لا توجد فاتورة لهذا الطلب', ['invoice_id' => null]);
        }

        return $this->success('رقم الفاتورة', ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->number]);
    }

    /**
     * POST /api/orders/batch-invoice-ids
     * Returns invoice IDs for multiple order IDs at once.
     */
    public function batchInvoiceIds(Request $request): JsonResponse
    {
        $request->validate(['order_ids' => 'required|array', 'order_ids.*' => 'integer']);

        $ids = $request->input('order_ids', []);
        $invoices = Invoice::whereIn('order_id', $ids)->get(['id', 'order_id', 'number']);

        $map = $invoices->mapWithKeys(fn($inv) => [$inv->order_id => ['invoice_id' => $inv->id, 'invoice_number' => $inv->number]]);

        return $this->success('أرقام الفواتير', ['map' => $map]);
    }
}
