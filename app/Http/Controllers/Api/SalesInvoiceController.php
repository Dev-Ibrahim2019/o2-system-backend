<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SalesInvoice\StoreSalesInvoiceRequest;
use App\Http\Requests\Api\SalesInvoice\UpdateSalesInvoiceRequest;
use App\Models\Invoice;
use App\Services\SalesInvoice\PosSalesSyncService;
use App\Services\SalesInvoice\SalesInvoiceExcelImportService;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesInvoiceController extends Controller
{
    use ApiResponses;

    public function __construct(
        protected SalesInvoiceExcelImportService $importService,
        protected PosSalesSyncService $posSyncService,
    ) {}

    /**
     * GET /api/sales-invoices
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) ($request->per_page ?? 25);

        $query = Invoice::with(['branch', 'customer']);

        // Auto-filter by branch for non-admin users
        $user = $request->user();
        if ($user && ! $user->hasRole('super-admin') && ! $user->hasRole('admin')) {
            $query->where('branch_id', $user->branch_id);
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('statuses')) {
            $statuses = is_array($request->statuses) ? $request->statuses : explode(',', $request->statuses);
            if (!empty($statuses)) {
                $query->whereIn('status', $statuses);
            }
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }
        if ($request->filled('from_date')) {
            $query->where('invoice_date', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->where('invoice_date', '<=', $request->to_date);
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        // Amount range
        if ($request->filled('min_amount')) {
            $query->where('total', '>=', $request->min_amount);
        }
        if ($request->filled('max_amount')) {
            $query->where('total', '<=', $request->max_amount);
        }
        // "Not approved" filter = draft OR no approval timestamp
        if ($request->filled('not_approved') && $request->boolean('not_approved')) {
            $query->where(function ($q) {
                $q->whereIn('status', ['draft', 'awaiting_approval'])
                  ->orWhereNull('approved_at');
            });
        }
        // Payment-method filter: invoices that have a payment of that method
        if ($request->filled('payment_method')) {
            $method = $request->payment_method;
            $query->whereHas('payments', function ($pq) use ($method) {
                $pq->where('method', $method);
            });
        }
        // "Has cash payment" == cash method exists
        if ($request->boolean('has_cash')) {
            $query->whereHas('payments', function ($pq) {
                $pq->where('method', 'cash');
            });
        }
        if ($request->boolean('has_credit')) {
            $query->whereHas('payments', function ($pq) {
                $pq->where('method', 'credit_card');
            });
        }

        $invoices = $query->orderByDesc('invoice_date')->paginate($perPage);

        return $this->success('تم جلب الفواتير بنجاح', $invoices);
    }

    /**
     * GET /api/sales-invoices/{invoice}
     */
    public function show(int $id): JsonResponse
    {
        $invoice = Invoice::with(['branch', 'customer', 'items', 'payments'])
            ->where('id', $id)
            ->firstOrFail();

        // Transform to match frontend expectations
        $invoice->items->each(function ($item) {
            $item->subtotal = (float) $item->quantity * (float) $item->unit_price;
        });

        return $this->success('تم جلب الفاتورة', $invoice);
    }

    /**
     * POST /api/sales-invoices
     * Saves to the invoices table (POS table).
     */
    public function store(StoreSalesInvoiceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        // Save to invoices table (POS table)
        $invoice = $this->createInvoiceRecord($data, $user);

        return $this->success('تم إنشاء الفاتورة بنجاح', $invoice, 201);
    }

    /**
     * PUT /api/sales-invoices/{invoice}
     */
    public function update(UpdateSalesInvoiceRequest $request, int $id): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $invoice = $this->updateInvoiceRecord($id, $data, $user);

        return $this->success('تم تعديل الفاتورة بنجاح', $invoice);
    }

    /**
     * DELETE /api/sales-invoices/{invoice}
     */
    public function destroy(int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        $invoice->items()->delete();
        $invoice->payments()->delete();
        $invoice->delete();
        return $this->success('تم حذف الفاتورة بنجاح', null);
    }

    /**
     * POST /api/sales-invoices/bulk-approve
     * Approves a batch of invoice IDs (draft + awaiting_approval) → awaiting_payment.
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        $ids = $request->input('ids');
        $query = Invoice::whereIn('id', $ids)
            ->whereIn('status', ['draft', 'awaiting_approval', 'awaiting_payment']);

        // Auto-restrict by branch for non-admin users
        $user = $request->user();
        if ($user && ! $user->hasRole('super-admin') && ! $user->hasRole('admin')) {
            $query->where('branch_id', $user->branch_id);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        $approvable = $query->get();
        $skipped = count($ids) - $approvable->count();

        foreach ($approvable as $invoice) {
            // If already has full payments, recalculate totals (auto-paid); otherwise mark as awaiting_payment.
            $invoice->recalculateTotals();
            if ($invoice->status !== 'paid') {
                $invoice->update([
                    'status' => 'awaiting_payment',
                    'approved_by' => $request->user()->id ?? null,
                    'approved_at' => now(),
                ]);
            }
        }

        return $this->success('تمت العملية', [
            'approved' => $approvable->count(),
            'skipped' => max(0, $skipped),
        ]);
    }

    /**
     * POST /api/sales-invoices/bulk-post
     * Post (= ترحيل) a batch of approved invoices. The opposite of approve.
     * Moves awaiting_payment → paid (closure), or for partial: paid if 0 remaining else partial.
     */
    public function bulkPost(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $ids = $request->input('ids');
        $query = Invoice::whereIn('id', $ids)
            ->whereNotIn('status', ['draft', 'cancelled', 'paid']);

        $user = $request->user();
        if ($user && ! $user->hasRole('super-admin') && ! $user->hasRole('admin')) {
            $query->where('branch_id', $user->branch_id);
        }

        $candidates = $query->get();
        $skipped = count($ids) - $candidates->count();
        $posted = 0;

        foreach ($candidates as $invoice) {
            $invoice->recalculateTotals();
            if ($invoice->status === 'paid') {
                $posted++;
            } else {
                $invoice->update(['status' => 'awaiting_payment']);
                $posted++;
            }
        }

        return $this->success('تمت العملية', ['posted' => $posted, 'skipped' => max(0, $skipped)]);
    }

    /**
     * GET /api/sales-invoices/group
     * Group invoices by a given field and return summary (count + totals).
     * Used for the "تجميع الفواتير" feature on the list page.
     */
    public function group(Request $request): JsonResponse
    {
        $request->validate([
            'group_by' => 'required|in:status,type,branch_id,customer_id,method,payment_method,month',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        $groupBy = $request->query('group_by');
        $query = Invoice::query();

        $user = $request->user();
        if ($user && ! $user->hasRole('super-admin') && ! $user->hasRole('admin')) {
            $query->where('branch_id', $user->branch_id);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('from_date')) {
            $query->where('invoice_date', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->where('invoice_date', '<=', $request->to_date);
        }

        $select = [
            DB::raw("IFNULL({$groupBy}, 'no_value') as group_key"),
            DB::raw('COUNT(*) as count'),
            DB::raw('SUM(total) as total_sum'),
            DB::raw('SUM(paid_amount) as paid_sum'),
            DB::raw('SUM(remaining_amount) as remaining_sum'),
        ];

        if ($groupBy === 'month') {
            $select[0] = DB::raw("DATE_FORMAT(invoice_date, '%Y-%m') as group_key");
        }

        $rows = $query->select($select)
            ->groupBy('group_key')
            ->orderByDesc('total_sum')
            ->get();

        return $this->success('تم التجميع', [
            'group_by' => $groupBy,
            'groups' => $rows,
        ]);
    }

    /**
     * POST /api/sales-invoices/{invoice}/approve
     * Approve = move from draft/awaiting_approval → awaiting_payment
     */
    public function approve(int $id, Request $request): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        if (in_array($invoice->status, ['cancelled', 'paid'])) {
            return $this->error('لا يمكن تعميد فاتورة ملغاة أو مدفوعة', 422);
        }

        $invoice->update([
            'status' => 'awaiting_payment',
            'approved_by' => $request->user()->id ?? null,
            'approved_at' => now(),
        ]);

        return $this->success('تم تعميد الفاتورة بنجاح', $invoice->fresh(['branch', 'customer', 'items', 'payments']));
    }

    /**
     * POST /api/sales-invoices/{invoice}/cancel
     */
    public function cancel(int $id, Request $request): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        if ($invoice->status === 'paid') {
            return $this->error('لا يمكن إلغاء فاتورة مدفوعة', 422);
        }

        $invoice->update(['status' => 'cancelled']);

        return $this->success('تم إلغاء الفاتورة بنجاح', $invoice->fresh(['branch', 'customer', 'items', 'payments']));
    }

    /**
     * POST /api/sales-invoices/{invoice}/payments
     */
    public function storePayment(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'method' => 'required|string|in:cash,credit_card,bank_transfer,app,account',
            'amount' => 'required|numeric|min:0.01',
            'reference_number' => 'nullable|string|max:100',
            'paid_at' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
        ]);

        $invoice = Invoice::findOrFail($id);

        if (in_array($invoice->status, ['draft', 'cancelled'])) {
            return $this->error('لا يمكن تسجيل دفعة على فاتورة مسودة أو ملغاة', 422);
        }

        $invoice->payments()->create([
            'method' => $request->method,
            'amount' => $request->amount,
            'reference_number' => $request->reference_number,
            'paid_at' => $this->normalizeDateTime($request->paid_at) ?? now(),
            'notes' => $request->notes,
            'number' => \App\Models\Payment::generateNumber(),
            'branch_id' => $invoice->branch_id,
            'user_id' => $request->user()->id ?? null,
        ]);

        $invoice->recalculateTotals();

        return $this->success('تم تسجيل الدفعة بنجاح', $invoice->fresh(['branch', 'customer', 'items', 'payments']));
    }

    /**
     * GET /api/sales-invoices/stats
     */
    public function stats(Request $request): JsonResponse
    {
        $filters = $request->only(['branch_id']);

        // Auto-filter by branch for non-admin users
        $user = $request->user();
        if ($user && ! $user->hasRole('super-admin') && ! $user->hasRole('admin')) {
            $filters['branch_id'] = $user->branch_id;
        }

        $query = Invoice::query();
        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        $total = (clone $query)->count();
        $awaitingPayment = (clone $query)->where('status', 'awaiting_payment')->count();
        // Total paid amount = sum of all payments across non-cancelled invoices
        $paidQuery = (clone $query)->whereNotIn('status', ['draft', 'cancelled', 'awaiting_approval']);
        $paidAmount = $paidQuery->sum('paid_amount');
        // Overdue = awaiting_payment past due date
        $overdueAmount = (clone $query)->where('status', 'awaiting_payment')
            ->where('due_date', '<', now())
            ->sum('remaining_amount');

        return $this->success('تم جلب الإحصائيات', compact('total', 'awaitingPayment', 'paidAmount', 'overdueAmount'));
    }

    // ═══════════════════════════════════════════════════════
    //  Excel Import
    // ═══════════════════════════════════════════════════════

    /**
     * POST /api/sales-invoices/import
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
            'tax_treatment' => 'nullable|in:inclusive,exclusive',
            'update_contact' => 'nullable|boolean',
        ]);

        $file = $request->file('file');
        $filePath = $file->store('imports/sales-invoices', 'local');

        $result = $this->importService->import(
            storage_path("app/{$filePath}"),
            [
                'tax_treatment' => $request->input('tax_treatment', 'exclusive'),
                'update_contact' => $request->boolean('update_contact', false),
            ],
            $request->user()
        );

        if (! empty($result['errors'])) {
            return $this->error('فشل الاستيراد', 422, $result['errors']);
        }

        return $this->success('تم استيراد الفواتير بنجاح', $result);
    }

    // ═══════════════════════════════════════════════════════
    //  POS Sync
    // ═══════════════════════════════════════════════════════

    /**
     * POST /api/sales-invoices/pos-sync/end-of-day
     */
    public function posSyncEndOfDay(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'date' => 'nullable|date',
            'pos_register_id' => 'nullable|string',
        ]);

        $invoice = $this->posSyncService->syncEndOfDay($request->validated(), $request->user());
        return $this->success('تم مزامنة مبيعات نهاية اليوم بنجاح', $invoice);
    }

    /**
     * POST /api/sales-invoices/pos-sync/batch
     */
    public function posSyncBatch(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        $result = $this->posSyncService->batchSync($request->validated(), $request->user());
        return $this->success('تمت المزامنة بنجاح', $result);
    }

    /**
     * POST /api/sales-invoices/pos-sync/single/{posInvoiceId}
     */
    public function posSyncSingle(int $posInvoiceId, Request $request): JsonResponse
    {
        $invoice = $this->posSyncService->syncSingleInvoice($posInvoiceId, $request->user());

        if (! $invoice) {
            return $this->success('الفاتورة تم نسخها مسبقاً', null);
        }

        return $this->success('تم نسخ فاتورة البيع بنجاح', $invoice);
    }

    /**
     * GET /api/sales-invoices/pos-invoices
     * Returns POS invoices (created from POS sync or POS register) not yet synced to sales module.
     */
    public function posInvoices(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $user = $request->user();
        $branchId = $request->input('branch_id');

        if (! $user->hasRole('super-admin') && ! $user->hasRole('admin')) {
            $branchId = $user->branch_id;
        }

        // POS invoices = those with pos_register_id (created from POS)
        $query = Invoice::with(['branch', 'customer'])
            ->whereNotNull('pos_register_id')
            ->whereNotIn('status', ['cancelled']);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $perPage = $request->input('per_page', 20);
        $posInvoices = $query->orderByDesc('invoice_date')->paginate($perPage);

        return $this->success('تم جلب فواتير نقطة البيع', $posInvoices);
    }

    /**
     * GET /api/sales-invoices/overdue
     */
    public function overdue(Request $request): JsonResponse
    {
        $query = Invoice::where('status', 'awaiting_payment')
            ->where('due_date', '<', now())
            ->with(['customer', 'branch']);

        $user = $request->user();
        if ($user && ! $user->hasRole('super-admin') && ! $user->hasRole('admin')) {
            $query->where('branch_id', $user->branch_id);
        }

        $invoices = $query->orderByDesc('due_date')->get();

        return $this->success('تم جلب الفواتير المتأخرة', $invoices);
    }

    // ═══════════════════════════════════════════════════════
    //  Helpers — create/update in invoices table
    // ═══════════════════════════════════════════════════════

    /**
     * Normalize datetime values for MySQL DATETIME column.
     * Accepts: null, ISO 8601 string, Unix timestamp, Carbon-string.
     * Returns: 'Y-m-d H:i:s' string or null.
     */
    protected function normalizeDateTime($value): ?string
    {
        if (empty($value)) return null;
        if ($value === 'now') return now()->format('Y-m-d H:i:s');

        try {
            // Detect ISO format from JS: "2026-07-04T12:55:56.000000Z"
            $dt = \Carbon\Carbon::parse($value);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function createInvoiceRecord(array $data, $user): Invoice
    {
        $invoice = Invoice::create([
            'number' => Invoice::generateNumber(),
            'type' => $data['type'] ?? 'simple_invoice',
            'customer_id' => $data['customer_id'] ?? null,
            'customer_name' => $data['customer_name'] ?? null,
            'entity_type' => $data['entity_type'] ?? 'customer',
            'entity_id' => $data['customer_id'] ?? null,
            'branch_id' => $data['branch_id'],
            'status' => $data['status'] ?? 'draft',
            'currency' => $data['currency'] ?? 'ILS',
            'payment_method' => null,
            'subtotal' => 0,
            'discount' => 0,
            'tax_total' => 0,
            'total' => 0,
            'paid_amount' => 0,
            'remaining_amount' => 0,
            'invoice_date' => $data['invoice_date'] ?? now(),
            'due_date' => $data['due_date'] ?? null,
            'supply_date' => $data['supply_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'reference_number' => $data['reference_number'] ?? null,
            'account_number' => $data['reference_number'] ?? null,
        ]);

        if (! empty($data['items'])) {
            foreach ($data['items'] as $itemData) {
                $qty = (float) ($itemData['quantity'] ?? 1);
                $price = (float) ($itemData['unit_price'] ?? 0);
                $disc = (float) ($itemData['discount'] ?? 0);
                $taxRate = (float) ($itemData['tax_rate'] ?? 0);
                $lineSubtotal = $qty * $price;
                $totalBeforeTax = max(0, $lineSubtotal - $disc);
                $taxAmt = $totalBeforeTax * ($taxRate / 100);
                $lineTotal = $totalBeforeTax + $taxAmt;

                $invoice->items()->create([
                    'item_id' => $itemData['item_id'] ?? null,
                    'item_name' => $itemData['item_name'] ?? '',
                    'description' => $itemData['description'] ?? null,
                    'quantity' => $qty,
                    'price' => $price,
                    'unit_price' => $price,
                    'discount' => $disc,
                    'tax_rate' => $taxRate,
                    'tax_amount' => round($taxAmt, 4),
                    'total_before_tax' => round($totalBeforeTax, 4),
                    'total' => round($lineTotal, 4),
                ]);
            }
        }

        if (! empty($data['payments'])) {
            foreach ($data['payments'] as $paymentData) {
                $invoice->payments()->create([
                    'method' => $paymentData['method'] ?? 'cash',
                    'amount' => (float) ($paymentData['amount'] ?? 0),
                    'reference_number' => $paymentData['reference_number'] ?? null,
                    'paid_at' => $this->normalizeDateTime($paymentData['paid_at'] ?? null) ?? now(),
                    'number' => \App\Models\Payment::generateNumber(),
                ]);
            }
        }

        $invoice->recalculateTotals();

        return $invoice->fresh(['branch', 'customer', 'items', 'payments']);
    }

    protected function updateInvoiceRecord(int $id, array $data, $user): Invoice
    {
        $invoice = Invoice::findOrFail($id);

        $invoice->update([
            'type' => $data['type'] ?? $invoice->type,
            'customer_id' => $data['customer_id'] ?? $invoice->customer_id,
            'customer_name' => $data['customer_name'] ?? $invoice->customer_name,
            'entity_type' => $data['entity_type'] ?? $invoice->entity_type,
            'entity_id' => $data['customer_id'] ?? $invoice->entity_id,
            'branch_id' => $data['branch_id'] ?? $invoice->branch_id,
            'invoice_date' => $data['invoice_date'] ?? $invoice->invoice_date,
            'due_date' => $data['due_date'] ?? $invoice->due_date,
            'supply_date' => $data['supply_date'] ?? $invoice->supply_date,
            'currency' => $data['currency'] ?? $invoice->currency,
            'notes' => $data['notes'] ?? $invoice->notes,
            'reference_number' => $data['reference_number'] ?? $invoice->reference_number,
            'account_number' => $data['reference_number'] ?? $invoice->account_number,
        ]);

        if (isset($data['items'])) {
            $incomingIds = collect($data['items'])->pluck('id')->filter()->toArray();
            $invoice->items()->whereNotIn('id', $incomingIds)->delete();

            foreach ($data['items'] as $itemData) {
                $qty = (float) ($itemData['quantity'] ?? 1);
                $price = (float) ($itemData['unit_price'] ?? 0);
                $disc = (float) ($itemData['discount'] ?? 0);
                $taxRate = (float) ($itemData['tax_rate'] ?? 0);
                $lineSubtotal = $qty * $price;
                $totalBeforeTax = max(0, $lineSubtotal - $disc);
                $taxAmt = $totalBeforeTax * ($taxRate / 100);
                $lineTotal = $totalBeforeTax + $taxAmt;

                if (! empty($itemData['id'])) {
                    $item = $invoice->items()->findOrFail($itemData['id']);
                    $item->update([
                        'item_id' => $itemData['item_id'] ?? $item->item_id,
                        'item_name' => $itemData['item_name'] ?? $item->item_name,
                        'description' => $itemData['description'] ?? $item->description,
                        'quantity' => $qty,
                        'price' => $price,
                        'unit_price' => $price,
                        'discount' => $disc,
                        'tax_rate' => $taxRate,
                        'tax_amount' => round($taxAmt, 4),
                        'total_before_tax' => round($totalBeforeTax, 4),
                        'total' => round($lineTotal, 4),
                    ]);
                } else {
                    $invoice->items()->create([
                        'item_id' => $itemData['item_id'] ?? null,
                        'item_name' => $itemData['item_name'] ?? '',
                        'description' => $itemData['description'] ?? null,
                        'quantity' => $qty,
                        'price' => $price,
                        'unit_price' => $price,
                        'discount' => $disc,
                        'tax_rate' => $taxRate,
                        'tax_amount' => round($taxAmt, 4),
                        'total_before_tax' => round($totalBeforeTax, 4),
                        'total' => round($lineTotal, 4),
                    ]);
                }
            }
        }

        if (isset($data['payments'])) {
            $incomingPaymentIds = collect($data['payments'])->pluck('id')->filter()->toArray();
            $invoice->payments()->whereNotIn('id', $incomingPaymentIds)->delete();

                        foreach ($data['payments'] as $paymentData) {
                // Sanitize: only allow expected fields + coerce paid_at to MySQL datetime
                $sanitized = [
                    'method' => $paymentData['method'] ?? 'cash',
                    'amount' => (float) ($paymentData['amount'] ?? 0),
                    'reference_number' => $paymentData['reference_number'] ?? null,
                    'paid_at' => $this->normalizeDateTime($paymentData['paid_at'] ?? null) ?? now(),
                    'notes' => $paymentData['notes'] ?? null,
                ];

                if (! empty($paymentData['id'])) {
                    $invoice->payments()->where('id', (int) $paymentData['id'])->update($sanitized);
                } else {
                    $invoice->payments()->create(array_merge($sanitized, [
                        'number' => \App\Models\Payment::generateNumber(),
                    ]));
                }
            }
        }

        $invoice->recalculateTotals();

        return $invoice->fresh(['branch', 'customer', 'items', 'payments']);
    }
}
