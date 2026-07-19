<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseBill;
use App\Models\PurchaseBillItem;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PurchaseBillController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PurchaseBill::with('supplier:id,name,code', 'creator:id,name');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('bill_number', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn($sq) => $sq->where('name', 'like', "%{$search}%"));
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($from = $request->input('from_date')) {
            $query->where('bill_date', '>=', $from);
        }
        if ($to = $request->input('to_date')) {
            $query->where('bill_date', '<=', $to);
        }

        if ($supplierId = $request->input('supplier_id')) {
            $query->where('supplier_id', $supplierId);
        }

        $perPage = $request->input('per_page', 20);
        $bills = $query->orderByDesc('bill_date')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $bills,
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $q = PurchaseBill::query();
        if ($supplierId = $request->input('supplier_id')) {
            $q->where('supplier_id', $supplierId);
        }

        $total = $q->count();
        $draft = (clone $q)->where('status', 'draft')->count();
        $pending = (clone $q)->where('status', 'pending_approval')->count();
        $unpaid = (clone $q)->where('status', 'unpaid')->count();
        $overdue = (clone $q)->where('status', 'overdue')->count();
        $partial = (clone $q)->where('status', 'partially_paid')->count();
        $paid = (clone $q)->where('status', 'paid')->count();

        $totalAmount = (clone $q)->sum('total');
        $totalPaid = (clone $q)->sum('paid_amount');

        return response()->json([
            'success' => true,
            'data' => compact('total', 'draft', 'pending', 'unpaid', 'overdue', 'partial', 'paid', 'totalAmount', 'totalPaid'),
        ]);
    }

    public function show(PurchaseBill $purchaseBill): JsonResponse
    {
        $purchaseBill->load(['items.product', 'items.account', 'supplier', 'creator', 'approver', 'attachments']);
        return response()->json(['success' => true, 'data' => $purchaseBill]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'currency' => 'required|string|max:10',
            'bill_date' => 'required|date',
            'due_date' => 'required|date',
            'status' => 'nullable|in:draft,pending_approval',
            'discount' => 'nullable|numeric|min:0',
            'reference' => 'nullable|string',
            'notes' => 'nullable|string',
            'branch_id' => 'nullable|exists:branches,id',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.account_id' => 'required|exists:accounts,id',
            'items.*.product_id' => 'nullable|exists:items,id',
        ]);

        $bill = DB::transaction(function () use ($validated, $request) {
            $subtotal = 0;
            $taxTotal = 0;
            $items = [];

            foreach ($validated['items'] as $item) {
                $qty = $item['quantity'];
                $price = $item['unit_price'];
                $itemDiscount = $item['discount'] ?? 0;
                $taxRate = $item['tax_rate'] ?? 0;

                $totalBeforeTax = ($qty * $price) - $itemDiscount;
                $taxAmount = $totalBeforeTax * ($taxRate / 100);
                $lineTotal = $totalBeforeTax + $taxAmount;

                $subtotal += $totalBeforeTax;
                $taxTotal += $taxAmount;

                $items[] = [
                    'description' => $item['description'],
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'discount' => $itemDiscount,
                    'total_before_tax' => $totalBeforeTax,
                    'line_total' => $lineTotal,
                    'account_id' => $item['account_id'],
                    'product_id' => $item['product_id'] ?? null,
                ];
            }

            $discount = $validated['discount'] ?? 0;
            $total = $subtotal + $taxTotal - $discount;

            $bill = PurchaseBill::create([
                'supplier_id' => $validated['supplier_id'],
                'currency' => $validated['currency'],
                'bill_date' => $validated['bill_date'],
                'due_date' => $validated['due_date'],
                'status' => $validated['status'] ?? 'draft',
                'subtotal' => $subtotal,
                'tax_total' => $taxTotal,
                'discount' => $discount,
                'total' => $total,
                'paid_amount' => 0,
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'branch_id' => $validated['branch_id'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            foreach ($items as $itemData) {
                $bill->items()->create($itemData);
            }

            return $bill;
        });

        $bill->load(['items.product', 'items.account', 'supplier', 'creator']);
        return response()->json(['success' => true, 'data' => $bill], 201);
    }

    public function update(Request $request, PurchaseBill $purchaseBill): JsonResponse
    {
        if (!in_array($purchaseBill->status, ['draft', 'pending_approval'])) {
            return response()->json(['success' => false, 'message' => 'لا يمكن تعديل فاتورة معتمدة أو مدفوعة'], 422);
        }

        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'currency' => 'required|string|max:10',
            'bill_date' => 'required|date',
            'due_date' => 'required|date',
            'status' => 'nullable|in:draft,pending_approval',
            'discount' => 'nullable|numeric|min:0',
            'reference' => 'nullable|string',
            'notes' => 'nullable|string',
            'branch_id' => 'nullable|exists:branches,id',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.account_id' => 'required|exists:accounts,id',
            'items.*.product_id' => 'nullable|exists:items,id',
        ]);

        DB::transaction(function () use ($validated, $purchaseBill) {
            $subtotal = 0;
            $taxTotal = 0;
            $itemsData = [];

            foreach ($validated['items'] as $item) {
                $qty = $item['quantity'];
                $price = $item['unit_price'];
                $itemDiscount = $item['discount'] ?? 0;
                $taxRate = $item['tax_rate'] ?? 0;

                $totalBeforeTax = ($qty * $price) - $itemDiscount;
                $taxAmount = $totalBeforeTax * ($taxRate / 100);
                $lineTotal = $totalBeforeTax + $taxAmount;

                $subtotal += $totalBeforeTax;
                $taxTotal += $taxAmount;

                $itemsData[] = [
                    'description' => $item['description'],
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'discount' => $itemDiscount,
                    'total_before_tax' => $totalBeforeTax,
                    'line_total' => $lineTotal,
                    'account_id' => $item['account_id'],
                    'product_id' => $item['product_id'] ?? null,
                ];
            }

            $discount = $validated['discount'] ?? 0;
            $total = $subtotal + $taxTotal - $discount;

            $purchaseBill->update([
                'supplier_id' => $validated['supplier_id'],
                'currency' => $validated['currency'],
                'bill_date' => $validated['bill_date'],
                'due_date' => $validated['due_date'],
                'status' => $validated['status'] ?? $purchaseBill->status,
                'subtotal' => $subtotal,
                'tax_total' => $taxTotal,
                'discount' => $discount,
                'total' => $total,
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'branch_id' => $validated['branch_id'] ?? null,
            ]);

            $purchaseBill->items()->delete();
            foreach ($itemsData as $itemData) {
                $purchaseBill->items()->create($itemData);
            }
        });

        $purchaseBill->load(['items.product', 'items.account', 'supplier', 'creator']);
        return response()->json(['success' => true, 'data' => $purchaseBill]);
    }

    public function destroy(PurchaseBill $purchaseBill): JsonResponse
    {
        if (!in_array($purchaseBill->status, ['draft', 'pending_approval'])) {
            return response()->json(['success' => false, 'message' => 'لا يمكن حذف فاتورة معتمدة أو مدفوعة'], 422);
        }

        $purchaseBill->items()->delete();
        $purchaseBill->attachments()->delete();
        $purchaseBill->delete();

        return response()->json(['success' => true, 'message' => 'تم حذف الفاتورة']);
    }

    public function approve(PurchaseBill $purchaseBill): JsonResponse
    {
        if (!in_array($purchaseBill->status, ['draft', 'pending_approval'])) {
            return response()->json(['success' => false, 'message' => 'لا يمكن اعتماد هذه الفاتورة'], 422);
        }

        $purchaseBill->update([
            'status' => 'unpaid',
            'approved_by' => request()->user()?->id,
            'approved_at' => now(),
        ]);

        $purchaseBill->load(['items.product', 'items.account', 'supplier', 'creator', 'approver']);
        return response()->json(['success' => true, 'data' => $purchaseBill]);
    }

    public function cancel(PurchaseBill $purchaseBill): JsonResponse
    {
        if (in_array($purchaseBill->status, ['paid'])) {
            return response()->json(['success' => false, 'message' => 'لا يمكن إلغاء فاتورة مدفوعة بالكامل'], 422);
        }

        $purchaseBill->update(['status' => 'cancelled']);
        $purchaseBill->load(['items.product', 'items.account', 'supplier', 'creator']);
        return response()->json(['success' => true, 'data' => $purchaseBill]);
    }

    public function recordPayment(Request $request, PurchaseBill $purchaseBill): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string',
            'reference_number' => 'nullable|string',
            'payment_date' => 'required|date',
        ]);

        DB::transaction(function () use ($validated, $purchaseBill) {
            $newPaid = $purchaseBill->paid_amount + $validated['amount'];
            $newStatus = $newPaid >= $purchaseBill->total ? 'paid' : 'partially_paid';

            $purchaseBill->update([
                'paid_amount' => $newPaid,
                'status' => $newStatus,
            ]);
        });

        $purchaseBill->load(['items.product', 'items.account', 'supplier', 'creator']);
        return response()->json(['success' => true, 'data' => $purchaseBill]);
    }

    public function markOverdue(): JsonResponse
    {
        $today = now()->toDateString();
        PurchaseBill::where('status', 'unpaid')
            ->where('due_date', '<', $today)
            ->update(['status' => 'overdue']);

        return response()->json(['success' => true, 'message' => 'تم تحديث الفواتير المتأخرة']);
    }
}
