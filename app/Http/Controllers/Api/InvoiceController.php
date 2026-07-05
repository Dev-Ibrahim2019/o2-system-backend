<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\AddPaymentRequest;
use App\Http\Requests\Api\CreateInvoiceFromOrderRequest;
use App\Http\Resources\AccountingResources\TransactionResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Transaction;
use App\Services\AccountingService;
use App\Services\Invoice\InvoiceFromOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceController extends ApiController
{
    public function __construct(
        private readonly InvoiceFromOrderService $invoiceFromOrderService,
    ) {}

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
            $order->update(['status' => 'confirmed']);
        }

        if ($order->invoice()->exists()) {
            return $this->error('يوجد فاتورة مسبقة لهذا الطلب.', 422);
        }

        $data = $request->validated();

        DB::beginTransaction();
        try {
            $invoice = $this->invoiceFromOrderService->createFromOrder(
                $order,
                $data,
                $request->user()?->id
            );

            DB::commit();

            return $this->success(
                'تم إنشاء الفاتورة',
                new InvoiceResource($invoice),
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

        DB::beginTransaction();
        try {
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

            $newPaid = $invoice->fresh()->paidAmount();
            $journalEntry = null;

            if ($newPaid >= (float) $invoice->total - 0.001) {
                $invoice->update([
                    'status' => 'paid',
                    'payment_method' => $data['method'],
                ]);

                if ($invoice->order_id) {
                    $invoice->order()->update(['status' => 'paid']);
                }

                $journalEntry = app(AccountingService::class)
                    ->createJournalEntryForInvoice($invoice->fresh());
            } elseif ($newPaid > 0) {
                $invoice->update(['status' => 'partial']);
            }

            DB::commit();

            return $this->success(
                'تم تسجيل الدفعة',
                [
                    'payment' => new PaymentResource($payment),
                    'invoice' => new InvoiceResource($invoice->fresh()->load(['items.discount', 'payments', 'order'])),
                    'journal_entry' => $journalEntry,
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

        $transaction = Transaction::with(['entries.account', 'entries.costCenter', 'branch', 'user', 'source'])
            ->where('source_type', Order::class)
            ->where('source_id', $invoice->order_id)
            ->where('type', 'sale')
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
        $query = Invoice::with(['items.discount', 'payments', 'order', 'branch']);

        if ($request->has('branch_id') && $request->branch_id !== '') {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->has('order_id') && $request->order_id !== '') {
            $query->where('order_id', $request->order_id);
        }

        if ($request->has('status') && $request->status !== '') {
            $query->where('status', $request->status);
        }

        if ($request->has('from') && $request->from !== '') {
            $query->whereDate('invoice_date', '>=', $request->from);
        }

        if ($request->has('to') && $request->to !== '') {
            $query->whereDate('invoice_date', '<=', $request->to);
        }

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

        $query->orderByDesc('created_at');

        $invoices = $query->get();

        return $this->success('החשבונות נמשכו בהצלחה', InvoiceResource::collection($invoices));
    }
}
