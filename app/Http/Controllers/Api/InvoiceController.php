<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\AddPaymentRequest;
use App\Http\Requests\Api\CreateInvoiceFromOrderRequest;
use App\Http\Resources\AccountingResources\TransactionResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\PosRegister;
use App\Models\Transaction;
use App\Services\AccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceController extends ApiController
{
    /**
     * إنشاء فاتورة رسمية من الطلب — بعد تقسيمه للأقسام (تذاكر) وقبل/أثناء الدفع
     */
    public function createFromOrder(CreateInvoiceFromOrderRequest $request, Order $order): JsonResponse
    {
        if (in_array($order->status, ['cancelled', 'paid'], true)) {
            return $this->error('لا يمكن إنشاء فاتورة لهذا الطلب.', 422);
        }

        if (! $order->tickets()->exists()) {
            return $this->error('الطلب غير مقسّم للأقسام — نفّذ confirm أولاً.', 422);
        }

        if ($order->status === 'pending') {
            // إذا وُجدت تذاكر بالفعل، فالمقصود أن الطلب مرتبط بالأقسام حتى لو بقيت الحالة مؤقتاً.
            $order->update(['status' => 'confirmed']);
        }

        if ($order->invoice()->exists()) {
            return $this->error('يوجد فاتورة مسبقة لهذا الطلب.', 422);
        }

        $data = $request->validated();

        // ── جلب معلومات نقطة البيع من الهيدر ──
        $deviceUuid = $request->header('X-Device-UUID');
        $posRegister = $deviceUuid ? PosRegister::where('device_uuid', $deviceUuid)->first() : null;
        $user = $request->user();

        DB::beginTransaction();
        try {
            $invoice = Invoice::create([
                'number' => Invoice::generateNumber(),
                'order_id' => $order->id,
                'customer_id' => $data['customer_id'] ?? null,
                'branch_id' => $order->branch_id,
                'status' => 'draft',
                'subtotal' => $order->subtotal,
                'discount' => $order->discount_amount,
                'total' => $order->total,
                'invoice_date' => now(),
                'notes' => $data['notes'] ?? $order->note,
                // ── معلومات نقطة البيع (POS) ──
                'pos_register_id' => $posRegister?->id,
                'pos_code'        => $posRegister?->code,
                'pos_name'        => $posRegister?->name,
                // ── معلومات فتح الفاتورة ──
                'opened_by'       => $user?->id,
                'opened_at'       => now(),
                'currency'        => $data['currency'] ?? 'ILS',
                'account_number'  => $data['account_number'] ?? null,
            ]);

            $orderItems = $order->items()->where('status', '!=', 'cancelled')->get();

            if ($orderItems->isEmpty()) {
                throw new \InvalidArgumentException('لا توجد أصناف صالحة للفوترة.');
            }

            foreach ($orderItems as $orderItem) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'item_id' => $orderItem->item_id,
                    'item_name' => $orderItem->item_name,
                    'quantity' => $orderItem->quantity,
                    'price' => $orderItem->price,
                    'total' => $orderItem->total,
                ]);
            }

            DB::commit();

            return $this->success(
                'تم إنشاء الفاتورة',
                new InvoiceResource($invoice->load(['items', 'payments', 'order'])),
                201
            );
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->error('فشل إنشاء الفاتورة: ' . $e->getMessage(), 500);
        }
    }

    /**
     * إضافة دفعة — partial أو paid + تحديث الطلب عند السداد الكامل
     */
    public function addPayment(AddPaymentRequest $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->status === 'cancelled') {
            return $this->error('الفاتورة ملغاة.', 422);
        }

        if ($invoice->status === 'paid') {
            return $this->error('الفاتورة مدفوعة بالكامل.', 422);
        }

        $data = $request->validated();
        $amount = (float) $data['amount'];
        $remaining = $invoice->remainingAmount();

        if ($amount > $remaining + 0.001) {
            return $this->error("المبلغ يتجاوز المتبقي ({$remaining}).", 422);
        }

        // [TRACE] تسجيل البيانات القادمة من Frontend
        logger()->info('InvoiceController::addPayment - incoming request', [
            'method' => $data['method'] ?? null,
            'amount' => $data['amount'] ?? null,
            'entity_type' => $data['entity_type'] ?? null,
            'entity_id' => $data['entity_id'] ?? null,
            'subledger_type' => $data['subledger_type'] ?? null,
            'subledger_id' => $data['subledger_id'] ?? null,
            'all_data' => $data,
        ]);

        DB::beginTransaction();
        try {
            // Determine entity type/id from request (supports both entity_* and subledger_* naming)
            $entityType = $data['entity_type'] ?? $data['subledger_type'] ?? null;
            $entityId = $data['entity_id'] ?? $data['subledger_id'] ?? null;

            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'number' => Payment::generateNumber(),
                'method' => $data['method'],
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'amount' => $amount,
                'paid_at' => now(),
                'notes' => $data['notes'] ?? null,
                'branch_id' => $data['branch_id'] ?? $invoice->branch_id,
                'user_id' => $request->user()?->id,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'subledger_type' => $entityType,
                'subledger_id' => $entityId,
            ]);

            $newPaid = $invoice->paidAmount();

            if ($newPaid >= (float) $invoice->total - 0.001) {
                $invoice->update([
                    'status' => 'paid',
                    'payment_method' => $data['method'],
                    // ── معلومات إغلاق الفاتورة ──
                    'closed_by' => $request->user()?->id,
                    'closed_at' => now(),
                ]);

                if ($invoice->order_id) {
                    $invoice->order()->update(['status' => 'paid']);
                }

                $journalEntry = app(AccountingService::class)->createJournalEntryForInvoice($invoice);
            } elseif ($newPaid > 0) {
                $invoice->update(['status' => 'partial']);
            }

            DB::commit();

            return $this->success(
                'تم تسجيل الدفعة',
                [
                    'payment' => new PaymentResource($payment),
                    'invoice' => new InvoiceResource($invoice->fresh()->load(['items', 'payments', 'order'])),
                    'journal_entry' => $journalEntry ?? null,
                ],
                201
            );
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->error('فشل تسجيل الدفعة: ' . $e->getMessage(), 500);
        }
    }

    /**
     * عرض القيد المحاسبي المرتبط بفاتورة
     */
    public function journalEntry(Invoice $invoice): JsonResponse
    {
        if (! $invoice->order_id) {
            return $this->error('لا يوجد طلب مرتبط بهذه الفاتورة.', 422);
        }

        $transaction = Transaction::with(['entries.account', 'entries.costCenter', 'branch', 'user'])
            ->where('source_type', Order::class)
            ->where('source_id', $invoice->order_id)
            ->where('type', 'sales')
            ->first();

        if (! $transaction) {
            return $this->error('لا يوجد قيد محاسبي لهذه الفاتورة بعد.', 404);
        }

        return $this->success('القيد المحاسبي', new TransactionResource($transaction));
    }

    /**
     * استلام רשימת החשבונות עם מסננים אופציונליים
     */
    public function index(Request $request): JsonResponse
    {
        $query = Invoice::with(['items', 'payments', 'order', 'branch']);

        // סינון לפי סניף
        if ($request->has('branch_id') && $request->branch_id !== '') {
            $query->where('branch_id', $request->branch_id);
        }

        // סינון לפי מזהה הזמנה
        if ($request->has('order_id') && $request->order_id !== '') {
            $query->where('order_id', $request->order_id);
        }

        // סינון לפי סטטוס
        if ($request->has('status') && $request->status !== '') {
            $query->where('status', $request->status);
        }

        // סינון לפי תאריך התחלה
        if ($request->has('from') && $request->from !== '') {
            $query->whereDate('invoice_date', '>=', $request->from);
        }

        // סינון לפי תאריך סיום
        if ($request->has('to') && $request->to !== '') {
            $query->whereDate('invoice_date', '<=', $request->to);
        }

        // סינון לפי חיפוש (במספר החשבון, מספר ההזמנה או שם הלקוח)
        if ($request->has('search') && $request->search !== '') {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhereHas('order', function ($q) use ($search) {
                        $q->where('order_number', 'like', "%{$search}%");
                    })
                    ->orWhereHas('order', function ($q) use ($search) {
                        $q->where('customer_name', 'like', "%{$search}%");
                    });
            });
        }

        // מיון לפי תאריך יצירה יורד (הכי חדש קודם)
        $query->orderByDesc('created_at');

        $invoices = $query->get();

        return $this->success('החשבונות נמשכו בהצלחה', InvoiceResource::collection($invoices));
    }

    // ══════════════════════════════════════════════
    //  Financial Invoices — CRUD + Stats + Approve/Void
    // ══════════════════════════════════════════════

    public function financialIndex(Request $request): JsonResponse
    {
        $query = Invoice::with(['items', 'payments', 'branch']);

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('number', 'like', "%{$s}%")
                    ->orWhere('notes', 'like', "%{$s}%");
            });
        }
        if ($request->filled('from')) {
            $query->whereDate('invoice_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('invoice_date', '<=', $request->to);
        }

        $perPage = (int) $request->get('per_page', 15);
        $invoices = $query->orderByDesc('created_at')->paginate($perPage);

        return $this->success('فواتير المبيعات', [
            'data' => InvoiceResource::collection($invoices->items()),
            'current_page' => $invoices->currentPage(),
            'last_page' => $invoices->lastPage(),
            'per_page' => $invoices->perPage(),
            'total' => $invoices->total(),
        ]);
    }

    public function financialStats(Request $request): JsonResponse
    {
        $base = Invoice::query();
        if ($request->filled('from')) {
            $base->whereDate('invoice_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $base->whereDate('invoice_date', '<=', $request->to);
        }

        $total = (clone $base)->count();
        $draft = (clone $base)->where('status', 'draft')->count();
        $pending = (clone $base)->where('status', 'pending')->count();
        $partial = (clone $base)->where('status', 'partial')->count();
        $paid = (clone $base)->where('status', 'paid')->count();
        $cancelled = (clone $base)->where('status', 'cancelled')->count();
        $totalAmount = (clone $base)->sum('total');
        $paidAmount = (clone $base)->where('status', 'paid')->sum('total');

        return $this->success('إحصائيات الفواتير', [
            'total' => $total,
            'draft' => $draft,
            'pending' => $pending,
            'partial' => $partial,
            'paid' => $paid,
            'cancelled' => $cancelled,
            'total_amount' => (float) $totalAmount,
            'paid_amount' => (float) $paidAmount,
        ]);
    }

    public function financialShow(Invoice $invoice): JsonResponse
    {
        $invoice->load(['items', 'payments', 'branch']);
        return $this->success('تفاصيل الفاتورة', new InvoiceResource($invoice));
    }

    public function financialStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'nullable|string',
            'entity_type' => 'nullable|string|in:customer,employee,supplier',
            'entity_id' => 'nullable|integer',
            'branch_id' => 'required|integer|exists:branches,id',
            'currency' => 'nullable|string|max:10',
            'subtotal' => 'required|numeric|min:0',
            'tax_total' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'total' => 'required|numeric|min:0',
            'invoice_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'delivery_date' => 'nullable|date',
            'expected_payment_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'nullable|integer',
            'items.*.item_name' => 'required|string',
            'items.*.description' => 'nullable|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.total_before_tax' => 'nullable|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_amount' => 'nullable|numeric|min:0',
            'items.*.total' => 'required|numeric|min:0',
            'items.*.account_id' => 'nullable|integer',
            'items.*.branch_id' => 'nullable|integer',
            'payments' => 'nullable|array',
            'payments.*.method' => 'required_with:payments|string',
            'payments.*.amount' => 'required_with:payments|numeric|min:0',
            'payments.*.notes' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $totalPaid = 0;
            if (!empty($validated['payments'])) {
                $totalPaid = collect($validated['payments'])->sum('amount');
            }

            $invoice = Invoice::create([
                'number' => Invoice::generateNumber(),
                'type' => $validated['type'] ?? 'فاتورة ضريبية',
                'entity_type' => $validated['entity_type'] ?? null,
                'entity_id' => $validated['entity_id'] ?? null,
                'customer_id' => $validated['entity_type'] === 'customer' ? $validated['entity_id'] : null,
                'branch_id' => $validated['branch_id'],
                'status' => $totalPaid >= $validated['total'] - 0.001 ? 'paid' : ($totalPaid > 0 ? 'partial' : 'draft'),
                'currency' => $validated['currency'] ?? 'SAR',
                'payment_method' => $validated['payments'][0]['method'] ?? null,
                'subtotal' => $validated['subtotal'],
                'discount' => $validated['discount'] ?? 0,
                'tax_total' => $validated['tax_total'] ?? 0,
                'total' => $validated['total'],
                'paid_amount' => $totalPaid,
                'remaining_amount' => max(0, $validated['total'] - $totalPaid),
                'invoice_date' => $validated['invoice_date'] ?? now(),
                'due_date' => $validated['due_date'] ?? null,
                'delivery_date' => $validated['delivery_date'] ?? null,
                'expected_payment_date' => $validated['expected_payment_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'item_id' => $item['item_id'] ?? null,
                    'item_name' => $item['item_name'],
                    'description' => $item['description'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'price' => $item['unit_price'],
                    'discount' => $item['discount'] ?? 0,
                    'total_before_tax' => $item['total_before_tax'] ?? 0,
                    'tax_rate' => $item['tax_rate'] ?? 15,
                    'tax_amount' => $item['tax_amount'] ?? 0,
                    'total' => $item['total'],
                    'account_id' => $item['account_id'] ?? null,
                    'branch_id' => $item['branch_id'] ?? $validated['branch_id'],
                ]);
            }

            if (!empty($validated['payments'])) {
                foreach ($validated['payments'] as $p) {
                    Payment::create([
                        'invoice_id' => $invoice->id,
                        'number' => Payment::generateNumber(),
                        'method' => $p['method'],
                        'amount' => $p['amount'],
                        'paid_at' => now(),
                        'notes' => $p['notes'] ?? null,
                        'branch_id' => $validated['branch_id'],
                        'user_id' => $request->user()?->id,
                    ]);
                }
            }

            DB::commit();

            return $this->success(
                'تم إنشاء الفاتورة',
                new InvoiceResource($invoice->fresh()->load(['items', 'payments', 'branch'])),
                201
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('فشل إنشاء الفاتورة: ' . $e->getMessage(), 500);
        }
    }

    public function financialUpdate(Request $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'nullable|string',
            'entity_type' => 'nullable|string|in:customer,employee,supplier',
            'entity_id' => 'nullable|integer',
            'branch_id' => 'required|integer|exists:branches,id',
            'currency' => 'nullable|string|max:10',
            'subtotal' => 'required|numeric|min:0',
            'tax_total' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'total' => 'required|numeric|min:0',
            'invoice_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'delivery_date' => 'nullable|date',
            'expected_payment_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'nullable|integer',
            'items.*.item_name' => 'required|string',
            'items.*.description' => 'nullable|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.total_before_tax' => 'nullable|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_amount' => 'nullable|numeric|min:0',
            'items.*.total' => 'required|numeric|min:0',
            'items.*.account_id' => 'nullable|integer',
            'items.*.branch_id' => 'nullable|integer',
        ]);

        DB::beginTransaction();
        try {
            $invoice->update([
                'type' => $validated['type'] ?? $invoice->type,
                'entity_type' => $validated['entity_type'] ?? null,
                'entity_id' => $validated['entity_id'] ?? null,
                'customer_id' => $validated['entity_type'] === 'customer' ? $validated['entity_id'] : null,
                'branch_id' => $validated['branch_id'],
                'currency' => $validated['currency'] ?? $invoice->currency,
                'subtotal' => $validated['subtotal'],
                'discount' => $validated['discount'] ?? 0,
                'tax_total' => $validated['tax_total'] ?? 0,
                'total' => $validated['total'],
                'invoice_date' => $validated['invoice_date'] ?? $invoice->invoice_date,
                'due_date' => $validated['due_date'] ?? null,
                'delivery_date' => $validated['delivery_date'] ?? null,
                'expected_payment_date' => $validated['expected_payment_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            $invoice->items()->delete();
            foreach ($validated['items'] as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'item_id' => $item['item_id'] ?? null,
                    'item_name' => $item['item_name'],
                    'description' => $item['description'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'price' => $item['unit_price'],
                    'discount' => $item['discount'] ?? 0,
                    'total_before_tax' => $item['total_before_tax'] ?? 0,
                    'tax_rate' => $item['tax_rate'] ?? 15,
                    'tax_amount' => $item['tax_amount'] ?? 0,
                    'total' => $item['total'],
                    'account_id' => $item['account_id'] ?? null,
                    'branch_id' => $item['branch_id'] ?? $validated['branch_id'],
                ]);
            }

            DB::commit();

            return $this->success(
                'تم تحديث الفاتورة',
                new InvoiceResource($invoice->fresh()->load(['items', 'payments', 'branch']))
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('فشل تحديث الفاتورة: ' . $e->getMessage(), 500);
        }
    }

    public function financialDestroy(Invoice $invoice): JsonResponse
    {
        $invoice->items()->delete();
        $invoice->delete();
        return $this->success('تم حذف الفاتورة');
    }

    public function approve(Invoice $invoice): JsonResponse
    {
        if ($invoice->status !== 'draft') {
            return $this->error('يمكن التعميد فقط للمسودات.', 422);
        }
        $invoice->update(['status' => 'pending']);
        return $this->success('تم تعميد الفاتورة', new InvoiceResource($invoice->fresh()->load(['items', 'payments', 'branch'])));
    }

    public function voidFinancial(Invoice $invoice): JsonResponse
    {
        if ($invoice->status === 'cancelled') {
            return $this->error('الفاتورة ملغاة بالفعل.', 422);
        }
        $invoice->update(['status' => 'cancelled']);
        return $this->success('تم إلغاء الفاتورة', new InvoiceResource($invoice->fresh()->load(['items', 'payments', 'branch'])));
    }
}
