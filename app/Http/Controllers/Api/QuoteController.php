<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuoteController extends Controller
{
    use ApiResponses;

    /**
     * GET /api/quotes
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) ($request->per_page ?? 25);

        $query = Quote::with(['client', 'items']);

        // Branch filter
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
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('quote_number', 'like', "%{$search}%")
                  ->orWhere('client_name', 'like', "%{$search}%")
                  ->orWhere('client_phone', 'like', "%{$search}%");
            });
        }
        if ($request->filled('from_date')) {
            $query->where('issue_date', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->where('issue_date', '<=', $request->to_date);
        }

        $quotes = $query->orderByDesc('id')->paginate($perPage);

        return $this->success('تم جلب عروض الأسعار', $quotes);
    }

    /**
     * GET /api/quotes/stats
     */
    public function stats(Request $request): JsonResponse
    {
        $query = Quote::query();

        $user = $request->user();
        if ($user && ! $user->hasRole('super-admin') && ! $user->hasRole('admin')) {
            $query->where('branch_id', $user->branch_id);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        $all = (clone $query)->count();
        $draft = (clone $query)->where('status', 'draft')->count();
        $sent = (clone $query)->where('status', 'sent')->count();
        $accepted = (clone $query)->where('status', 'accepted')->count();
        $rejected = (clone $query)->where('status', 'rejected')->count();
        $expired = (clone $query)->where('status', 'expired')->count();
        $converted = (clone $query)->where('status', 'converted')->count();
        $totalValue = (clone $query)->sum('total');

        return $this->success('إحصائيات عروض الأسعار', compact(
            'all', 'draft', 'sent', 'accepted', 'rejected', 'expired', 'converted', 'totalValue'
        ));
    }

    /**
     * GET /api/quotes/{id}
     */
    public function show($id): JsonResponse
    {
        $quote = Quote::with(['items', 'client', 'issuer', 'branch'])->findOrFail($id);
        return $this->success('تفاصيل عرض السعر', $quote);
    }

    /**
     * POST /api/quotes
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quote_number'   => 'nullable|string|max:50|unique:quotes,quote_number',
            'client_id'      => 'nullable|exists:customers,id',
            'client_name'    => 'nullable|string|max:255',
            'client_phone'   => 'nullable|string|max:50',
            'client_email'   => 'nullable|email|max:255',
            'branch_id'      => 'nullable|exists:branches,id',
            'issue_date'     => 'required|date',
            'expiry_date'    => 'nullable|date|after_or_equal:issue_date',
            'currency'       => 'nullable|string|max:10',
            'status'         => 'nullable|in:draft,sent,accepted,rejected,expired,converted',
            'notes'          => 'nullable|string',
            'terms'          => 'nullable|string',
            'items'          => 'nullable|array',
            'items.*.item_id'        => 'nullable|exists:items,id',
            'items.*.description'    => 'required|string|max:500',
            'items.*.sort_order'     => 'nullable|integer',
            'items.*.quantity'       => 'required|numeric|min:0',
            'items.*.unit_price'     => 'required|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_rate'       => 'nullable|numeric|min:0|max:100',
        ]);

        $result = DB::transaction(function () use ($validated, $request) {
            // Generate quote number if not provided
            if (empty($validated['quote_number'])) {
                $lastQuote = Quote::withTrashed()->orderByDesc('id')->first();
                $nextNum = 1;
                if ($lastQuote && preg_match('/(\d+)$/', $lastQuote->quote_number, $m)) {
                    $nextNum = (int) $m[1] + 1;
                }
                $validated['quote_number'] = 'QUO-' . str_pad($nextNum, 5, '0', STR_PAD_LEFT);
            }

            $validated['issuer_id'] = $request->user()?->id;

            $itemsData = $validated['items'] ?? [];
            unset($validated['items']);

            $quote = Quote::create($validated);

            // Create items and calculate totals
            $subtotal = 0;
            $taxTotal = 0;

            foreach ($itemsData as $idx => $itemData) {
                $qty = (float) ($itemData['quantity'] ?? 1);
                $price = (float) ($itemData['unit_price'] ?? 0);
                $discountPct = (float) ($itemData['discount_percent'] ?? 0);
                $taxRate = (float) ($itemData['tax_rate'] ?? 0);

                $lineSubtotal = $qty * $price;
                $discountAmount = $lineSubtotal * ($discountPct / 100);
                $afterDiscount = $lineSubtotal - $discountAmount;
                $taxAmount = $afterDiscount * ($taxRate / 100);
                $lineTotal = $afterDiscount + $taxAmount;

                QuoteItem::create([
                    'quote_id'         => $quote->id,
                    'item_id'          => $itemData['item_id'] ?? null,
                    'description'      => $itemData['description'],
                    'sort_order'       => $itemData['sort_order'] ?? $idx,
                    'quantity'         => $qty,
                    'unit_price'       => $price,
                    'discount_percent' => $discountPct,
                    'tax_rate'         => $taxRate,
                    'subtotal'         => $afterDiscount,
                    'tax_amount'       => $taxAmount,
                    'total'            => $lineTotal,
                ]);

                $subtotal += $afterDiscount;
                $taxTotal += $taxAmount;
            }

            $quote->update([
                'subtotal'   => $subtotal,
                'tax_total'  => $taxTotal,
                'total'      => $subtotal + $taxTotal,
            ]);

            return $quote->load('items');
        });

        return $this->success('تم إنشاء عرض السعر', $result, 201);
    }

    /**
     * PUT /api/quotes/{id}
     */
    public function update(Request $request, $id): JsonResponse
    {
        $quote = Quote::findOrFail($id);

        $validated = $request->validate([
            'client_id'      => 'nullable|exists:customers,id',
            'client_name'    => 'nullable|string|max:255',
            'client_phone'   => 'nullable|string|max:50',
            'client_email'   => 'nullable|email|max:255',
            'branch_id'      => 'nullable|exists:branches,id',
            'issue_date'     => 'nullable|date',
            'expiry_date'    => 'nullable|date',
            'currency'       => 'nullable|string|max:10',
            'status'         => 'nullable|in:draft,sent,accepted,rejected,expired,converted',
            'notes'          => 'nullable|string',
            'terms'          => 'nullable|string',
            'items'          => 'nullable|array',
            'items.*.item_id'        => 'nullable|exists:items,id',
            'items.*.description'    => 'required|string|max:500',
            'items.*.sort_order'     => 'nullable|integer',
            'items.*.quantity'       => 'required|numeric|min:0',
            'items.*.unit_price'     => 'required|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_rate'       => 'nullable|numeric|min:0|max:100',
        ]);

        $result = DB::transaction(function () use ($quote, $validated) {
            $itemsData = $validated['items'] ?? null;
            unset($validated['items']);

            $quote->update($validated);

            if ($itemsData !== null) {
                // Delete old items
                $quote->items()->delete();

                $subtotal = 0;
                $taxTotal = 0;

                foreach ($itemsData as $idx => $itemData) {
                    $qty = (float) ($itemData['quantity'] ?? 1);
                    $price = (float) ($itemData['unit_price'] ?? 0);
                    $discountPct = (float) ($itemData['discount_percent'] ?? 0);
                    $taxRate = (float) ($itemData['tax_rate'] ?? 0);

                    $lineSubtotal = $qty * $price;
                    $discountAmount = $lineSubtotal * ($discountPct / 100);
                    $afterDiscount = $lineSubtotal - $discountAmount;
                    $taxAmount = $afterDiscount * ($taxRate / 100);
                    $lineTotal = $afterDiscount + $taxAmount;

                    QuoteItem::create([
                        'quote_id'         => $quote->id,
                        'item_id'          => $itemData['item_id'] ?? null,
                        'description'      => $itemData['description'],
                        'sort_order'       => $itemData['sort_order'] ?? $idx,
                        'quantity'         => $qty,
                        'unit_price'       => $price,
                        'discount_percent' => $discountPct,
                        'tax_rate'         => $taxRate,
                        'subtotal'         => $afterDiscount,
                        'tax_amount'       => $taxAmount,
                        'total'            => $lineTotal,
                    ]);

                    $subtotal += $afterDiscount;
                    $taxTotal += $taxAmount;
                }

                $quote->update([
                    'subtotal'   => $subtotal,
                    'tax_total'  => $taxTotal,
                    'total'      => $subtotal + $taxTotal,
                ]);
            }

            return $quote->load('items');
        });

        return $this->success('تم تحديث عرض السعر', $result);
    }

    /**
     * DELETE /api/quotes/{id}
     */
    public function destroy($id): JsonResponse
    {
        $quote = Quote::findOrFail($id);
        $quote->items()->delete();
        $quote->delete();

        return $this->success('تم حذف عرض السعر');
    }

    /**
     * POST /api/quotes/{id}/send
     */
    public function send($id): JsonResponse
    {
        $quote = Quote::findOrFail($id);
        $quote->update(['status' => 'sent']);

        return $this->success('تم إرسال عرض السعر', $quote);
    }

    /**
     * POST /api/quotes/{id}/accept
     */
    public function accept($id): JsonResponse
    {
        $quote = Quote::findOrFail($id);
        $quote->update(['status' => 'accepted']);

        return $this->success('تم قبول عرض السعر', $quote);
    }

    /**
     * POST /api/quotes/{id}/reject
     */
    public function reject($id): JsonResponse
    {
        $quote = Quote::findOrFail($id);
        $quote->update(['status' => 'rejected']);

        return $this->success('تم رفض عرض السعر', $quote);
    }

    /**
     * POST /api/quotes/{id}/duplicate
     */
    public function duplicate($id): JsonResponse
    {
        $original = Quote::with('items')->findOrFail($id);

        // Generate new number
        $lastQuote = Quote::withTrashed()->orderByDesc('id')->first();
        $nextNum = 1;
        if ($lastQuote && preg_match('/(\d+)$/', $lastQuote->quote_number, $m)) {
            $nextNum = (int) $m[1] + 1;
        }
        $newNumber = 'QUO-' . str_pad($nextNum, 5, '0', STR_PAD_LEFT);

        $newQuote = $original->replicate([
            'quote_number' => $newNumber,
            'status'       => 'draft',
            'share_token'  => null,
            'converted_invoice_id' => null,
        ]);
        $newQuote->save();

        foreach ($original->items as $item) {
            $newItem = $item->replicate();
            $newItem->quote_id = $newQuote->id;
            $newItem->save();
        }

        return $this->success('تم تكرار عرض السعر', $newQuote->load('items'), 201);
    }

    /**
     * POST /api/quotes/{id}/convert
     */
    public function convertToInvoice(Request $request, $id): JsonResponse
    {
        $quote = Quote::with('items')->findOrFail($id);
        $quote->update(['status' => 'converted']);

        // TODO: Create sales invoice from quote when invoice module is ready
        // For now, just mark as converted
        return $this->success('تم تحويل عرض السعر (إنشاء فاتورة — قيد التطوير)', $quote);
    }
}
