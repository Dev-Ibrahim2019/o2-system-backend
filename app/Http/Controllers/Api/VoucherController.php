<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Models\VoucherAllocation;
use App\Models\Invoice;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VoucherController extends Controller
{
    use ApiResponses;

    /**
     * GET /api/vouchers
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) ($request->per_page ?? 25);

        $query = Voucher::with(['branch', 'paymentMethod', 'creator', 'approver']);

        // Branch filter
        $user = $request->user();
        if ($user && ! $user->hasRole('super-admin') && ! $user->hasRole('admin')) {
            $query->where('branch_id', $user->branch_id);
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->entity_type);
        }
        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }
        if ($request->filled('payment_method_id')) {
            $query->where('payment_method_id', $request->payment_method_id);
        }
        if ($request->filled('shift_id')) {
            $query->where('shift_id', $request->shift_id);
        }
        if ($request->filled('accounting_day_id')) {
            $query->where('accounting_day_id', $request->accounting_day_id);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                  ->orWhere('entity_name', 'like', "%{$search}%")
                  ->orWhere('reference_number', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }
        if ($request->filled('from_date')) {
            $query->where('voucher_date', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->where('voucher_date', '<=', $request->to_date);
        }
        if ($request->filled('created_by')) {
            $query->where('created_by', $request->created_by);
        }

        $vouchers = $query->orderByDesc('id')->paginate($perPage);

        return $this->success('تم جلب السندات', $vouchers);
    }

    /**
     * GET /api/vouchers/stats
     */
    public function stats(Request $request): JsonResponse
    {
        $query = Voucher::query();
        $user = $request->user();
        if ($user && ! $user->hasRole('super-admin') && ! $user->hasRole('admin')) {
            $query->where('branch_id', $user->branch_id);
        }

        $total = (clone $query)->count();
        $draft = (clone $query)->where('status', 'draft')->count();
        $active = (clone $query)->where('status', 'active')->count();
        $cancelled = (clone $query)->where('status', 'cancelled')->count();
        $receipts = (clone $query)->where('type', 'receipt')->where('status', 'active')->sum('amount');
        $payments = (clone $query)->where('type', 'payment')->where('status', 'active')->sum('amount');

        return $this->success('إحصائيات السندات', compact(
            'total', 'draft', 'active', 'cancelled', 'receipts', 'payments'
        ));
    }

    /**
     * GET /api/vouchers/{id}
     */
    public function show($id): JsonResponse
    {
        $voucher = Voucher::with(['allocations.invoice', 'branch', 'paymentMethod', 'creator', 'approver'])
            ->findOrFail($id);

        return $this->success('تفاصيل السند', $voucher);
    }

    /**
     * POST /api/vouchers
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'               => 'required|in:receipt,payment',
            'entity_type'        => 'required|in:customer,supplier',
            'entity_id'          => 'required|integer',
            'entity_name'        => 'nullable|string|max:255',
            'amount'             => 'required|numeric|min:0.01',
            'currency'           => 'nullable|string|max:10',
            'payment_method_id'  => 'nullable|exists:payment_methods,id',
            'payment_method_name'=> 'nullable|string|max:100',
            'reference_number'   => 'nullable|string|max:255',
            'branch_id'          => 'nullable|exists:branches,id',
            'shift_id'           => 'nullable|integer',
            'accounting_day_id'  => 'nullable|integer',
            'voucher_date'       => 'required|date',
            'status'             => 'nullable|in:draft,active',
            'notes'              => 'nullable|string',
            'allocations'        => 'nullable|array',
            'allocations.*.invoice_id' => 'required|exists:invoices,id',
            'allocations.*.amount'     => 'required|numeric|min:0.01',
        ]);

        // Validate entity_type matches type
        if ($validated['type'] === 'receipt' && $validated['entity_type'] !== 'customer') {
            return $this->error('سند القبض مخصص للعملاء فقط', 422);
        }
        if ($validated['type'] === 'payment' && $validated['entity_type'] !== 'supplier') {
            return $this->error('سند الصرف مخصص للموردين فقط', 422);
        }

        $result = DB::transaction(function () use ($validated, $request) {
            // Get entity balance before
            $balanceBefore = Voucher::getEntityBalance($validated['entity_type'], $validated['entity_id']);

            $validated['created_by'] = $request->user()?->id;
            $validated['balance_before'] = $balanceBefore;
            if (!isset($validated['entity_name'])) {
                // Try to get entity name
                if ($validated['entity_type'] === 'customer') {
                    $entity = \App\Models\Customer::find($validated['entity_id']);
                } else {
                    $entity = \App\Models\Supplier::find($validated['entity_id']);
                }
                $validated['entity_name'] = $entity?->name ?? '';
            }
            if (!isset($validated['payment_method_name']) && isset($validated['payment_method_id'])) {
                $pm = \App\Models\PaymentMethod::find($validated['payment_method_id']);
                $validated['payment_method_name'] = $pm?->name ?? '';
            }

            $allocationsData = $validated['allocations'] ?? [];
            unset($validated['allocations']);

            $voucher = Voucher::create($validated);

            // Create allocations and update invoices
            $totalAllocated = 0;
            foreach ($allocationsData as $alloc) {
                $invoice = Invoice::findOrFail($alloc['invoice_id']);

                VoucherAllocation::create([
                    'voucher_id'  => $voucher->id,
                    'invoice_id'  => $alloc['invoice_id'],
                    'amount'      => $alloc['amount'],
                ]);

                // Update invoice paid_amount
                $invoice->paid_amount = (float) $invoice->paid_amount + (float) $alloc['amount'];
                $invoice->remaining_amount = (float) $invoice->total - (float) $invoice->paid_amount;

                // Resolve status
                if ($invoice->remaining_amount <= 0) {
                    $invoice->status = 'paid';
                } elseif ($invoice->paid_amount > 0) {
                    $invoice->status = 'partial';
                }
                $invoice->save();

                $totalAllocated += $alloc['amount'];
            }

            return $voucher->fresh(['allocations.invoice', 'branch', 'paymentMethod', 'creator']);
        });

        return $this->success('تم إنشاء السند بنجاح', $result, 201);
    }

    /**
     * PUT /api/vouchers/{id}
     */
    public function update(Request $request, $id): JsonResponse
    {
        $voucher = Voucher::findOrFail($id);

        if ($voucher->status !== 'draft') {
            return $this->error('لا يمكن تعديل سند غير مسودة', 422);
        }

        $validated = $request->validate([
            'entity_id'          => 'nullable|integer',
            'entity_name'        => 'nullable|string|max:255',
            'amount'             => 'nullable|numeric|min:0.01',
            'currency'           => 'nullable|string|max:10',
            'payment_method_id'  => 'nullable|exists:payment_methods,id',
            'payment_method_name'=> 'nullable|string|max:100',
            'reference_number'   => 'nullable|string|max:255',
            'voucher_date'       => 'nullable|date',
            'notes'              => 'nullable|string',
            'allocations'        => 'nullable|array',
            'allocations.*.invoice_id' => 'required|exists:invoices,id',
            'allocations.*.amount'     => 'required|numeric|min:0.01',
        ]);

        $result = DB::transaction(function () use ($voucher, $validated, $request) {
            // Reverse old allocations
            foreach ($voucher->allocations as $oldAlloc) {
                $invoice = Invoice::find($oldAlloc->invoice_id);
                if ($invoice) {
                    $invoice->paid_amount = max(0, (float) $invoice->paid_amount - (float) $oldAlloc->amount);
                    $invoice->remaining_amount = (float) $invoice->total - (float) $invoice->paid_amount;
                    if ($invoice->remaining_amount <= 0) {
                        $invoice->status = 'paid';
                    } elseif ($invoice->paid_amount > 0) {
                        $invoice->status = 'partial';
                    } else {
                        $invoice->status = 'awaiting_payment';
                    }
                    $invoice->save();
                }
            }
            $voucher->allocations()->delete();

            $allocationsData = $validated['allocations'] ?? [];
            unset($validated['allocations']);

            $validated['created_by'] = $request->user()?->id;
            if (isset($validated['payment_method_id']) && !isset($validated['payment_method_name'])) {
                $pm = \App\Models\PaymentMethod::find($validated['payment_method_id']);
                $validated['payment_method_name'] = $pm?->name ?? '';
            }

            $voucher->update($validated);

            // Re-apply allocations
            foreach ($allocationsData as $alloc) {
                $invoice = Invoice::findOrFail($alloc['invoice_id']);

                VoucherAllocation::create([
                    'voucher_id'  => $voucher->id,
                    'invoice_id'  => $alloc['invoice_id'],
                    'amount'      => $alloc['amount'],
                ]);

                $invoice->paid_amount = (float) $invoice->paid_amount + (float) $alloc['amount'];
                $invoice->remaining_amount = (float) $invoice->total - (float) $invoice->paid_amount;
                if ($invoice->remaining_amount <= 0) {
                    $invoice->status = 'paid';
                } elseif ($invoice->paid_amount > 0) {
                    $invoice->status = 'partial';
                }
                $invoice->save();
            }

            return $voucher->fresh(['allocations.invoice', 'branch', 'paymentMethod', 'creator']);
        });

        return $this->success('تم تحديث السند', $result);
    }

    /**
     * DELETE /api/vouchers/{id}
     */
    public function destroy($id): JsonResponse
    {
        $voucher = Voucher::findOrFail($id);

        if ($voucher->status === 'active') {
            return $this->error('لا يمكن حذف سند نشط. ألغِه أولاً.', 422);
        }

        DB::transaction(function () use ($voucher) {
            // Reverse allocations
            foreach ($voucher->allocations as $alloc) {
                $invoice = Invoice::find($alloc->invoice_id);
                if ($invoice) {
                    $invoice->paid_amount = max(0, (float) $invoice->paid_amount - (float) $alloc->amount);
                    $invoice->remaining_amount = (float) $invoice->total - (float) $invoice->paid_amount;
                    if ($invoice->remaining_amount <= 0) {
                        $invoice->status = 'paid';
                    } elseif ($invoice->paid_amount > 0) {
                        $invoice->status = 'partial';
                    } else {
                        $invoice->status = 'awaiting_payment';
                    }
                    $invoice->save();
                }
            }
            $voucher->allocations()->delete();
            $voucher->delete();
        });

        return $this->success('تم حذف السند');
    }

    /**
     * POST /api/vouchers/{id}/activate
     */
    public function activate($id): JsonResponse
    {
        $voucher = Voucher::findOrFail($id);
        $voucher->update(['status' => 'active']);

        return $this->success('تم تفعيل السند', $voucher);
    }

    /**
     * POST /api/vouchers/{id}/cancel
     */
    public function cancel($id): JsonResponse
    {
        $voucher = Voucher::findOrFail($id);

        DB::transaction(function () use ($voucher) {
            // Reverse allocations
            foreach ($voucher->allocations as $alloc) {
                $invoice = Invoice::find($alloc->invoice_id);
                if ($invoice) {
                    $invoice->paid_amount = max(0, (float) $invoice->paid_amount - (float) $alloc->amount);
                    $invoice->remaining_amount = (float) $invoice->total - (float) $invoice->paid_amount;
                    if ($invoice->remaining_amount <= 0) {
                        $invoice->status = 'paid';
                    } elseif ($invoice->paid_amount > 0) {
                        $invoice->status = 'partial';
                    } else {
                        $invoice->status = 'awaiting_payment';
                    }
                    $invoice->save();
                }
            }

            $voucher->update(['status' => 'cancelled']);
        });

        return $this->success('تم إلغاء السند', $voucher);
    }

    /**
     * POST /api/vouchers/{id}/approve
     */
    public function approve(Request $request, $id): JsonResponse
    {
        $voucher = Voucher::findOrFail($id);
        $voucher->update([
            'status'     => 'active',
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
        ]);

        return $this->success('تم اعتماد السند', $voucher);
    }

    /**
     * GET /api/vouchers/{id}/entity-invoices
     * Get unpaid invoices for a specific entity
     */
    public function entityInvoices(Request $request, $id): JsonResponse
    {
        $voucher = Voucher::findOrFail($id);

        $invoices = Invoice::where('entity_type', $voucher->entity_type)
            ->where('entity_id', $voucher->entity_id)
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->where('remaining_amount', '>', 0)
            ->orderBy('invoice_date', 'asc')
            ->get();

        return $this->success('فواتير العميل', $invoices);
    }

    /**
     * GET /api/vouchers/entity-invoices
     * Get unpaid invoices for entity (before voucher creation)
     */
    public function getEntityInvoices(Request $request): JsonResponse
    {
        $entityType = $request->input('entity_type');
        $entityId = $request->input('entity_id');

        if (!$entityType || !$entityId) {
            return $this->error('entity_type و entity_id مطلوبان', 422);
        }

        $invoices = Invoice::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->where('remaining_amount', '>', 0)
            ->orderBy('invoice_date', 'asc')
            ->get();

        return $this->success('فواتير الجهة', $invoices);
    }
}
