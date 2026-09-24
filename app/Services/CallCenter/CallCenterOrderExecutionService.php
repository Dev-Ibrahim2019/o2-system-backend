<?php

namespace App\Services\CallCenter;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\IdempotencyRecord;
use App\Models\Order;
use App\Models\PaymentConfirmation;
use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Services\Accounting\SubledgerService;
use App\Services\Invoice\InvoicePaymentService;
use App\Services\Order\OrderConfirmationService;
use App\Services\Support\IdempotencyService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class CallCenterOrderExecutionService
{
    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly InvoicePaymentService $invoicePayments,
        private readonly OrderConfirmationService $orderConfirmation,
        private readonly SubledgerService $subledgers,
        private readonly \App\Services\Order\OrderPaymentService $orderPayments,
    ) {}

    public function saveOrderAwaitingBankConfirmation(Order $order, array $paymentMethodData): Order
    {
        $this->assertCallCenterOrder($order);
        if (! in_array($order->payment_policy, [null, Order::PAYMENT_POLICY_MANUAL_CONFIRMATION], true)
            || ! in_array($order->payment_status, [null, Order::PAYMENT_STATUS_AWAITING_CONFIRMATION, Order::PAYMENT_STATUS_FAILED], true)) {
            throw new UnprocessableEntityHttpException('حالة الدفع الحالية لا تسمح بانتظار تأكيد التحويل.');
        }
        $order->update([
            'payment_policy' => Order::PAYMENT_POLICY_MANUAL_CONFIRMATION,
            'payment_status' => Order::PAYMENT_STATUS_AWAITING_CONFIRMATION,
            'kitchen_release_status' => Order::KITCHEN_RELEASE_STATUS_HELD,
        ]);

        return $order->fresh();
    }

    public function confirmBankTransferAndRelease(
        Order $order,
        string $referenceNumber,
        int $paymentMethodId,
        float $amount,
        string $idempotencyKey,
        int $executedBy,
        array $evidence = [],
    ): Order {
        $this->assertCallCenterOrder($order);
        $this->assertManualConfirmationEligible($order);
        $normalizedReference = self::normalizeReference($referenceNumber);
        $payload = [
            'order_id' => $order->id,
            'reference_number' => $normalizedReference,
            'payment_method_id' => $paymentMethodId,
            'amount' => round($amount, 3),
        ];

        $this->financialPhase($order, 'call-center-transfer', $idempotencyKey, $payload, $executedBy,
            function (IdempotencyRecord $record, Order $lockedOrder) use ($referenceNumber, $normalizedReference, $paymentMethodId, $amount, $idempotencyKey, $executedBy, $evidence) {
                $method = PaymentMethod::active()->whereKey($paymentMethodId)->firstOrFail();
                if ($method->is_entity || ! in_array($method->type, ['bank', 'card', 'wallet'], true)) {
                    throw new UnprocessableEntityHttpException('طريقة الدفع لا تدعم التأكيد اليدوي.');
                }
                // عالميًا لكل طرق الدفع (مش لكل طريقة لحالها) — والرسالة بتقول أي طلب استخدمه
                if ($owner = $this->referenceOwner($normalizedReference)) {
                    throw new ConflictHttpException("الرقم المرجعي مستخدم مسبقًا بالطلب {$owner}.");
                }
                $invoice = $this->invoiceForPayment($lockedOrder, $amount);
                PaymentConfirmation::create([
                    'order_id' => $lockedOrder->id,
                    'payment_method_id' => $method->id,
                    'reference_number' => $referenceNumber,
                    'normalized_reference_number' => $normalizedReference,
                    'amount' => $amount,
                    'status' => PaymentConfirmation::STATUS_CONFIRMED,
                    'idempotency_key' => $idempotencyKey,
                    'confirmed_by' => $executedBy,
                    'confirmed_at' => now(),
                    'transferred_at' => $evidence['transferred_at'] ?? null,
                    'bank_name' => $evidence['bank_name'] ?? null,
                    'receipt_path' => $evidence['receipt_path'] ?? null,
                ]);
                // بنمرّر المرجع المُطبَّع للدفعة كمان، فالقيد الفريد الموجود على payments.reference_number
                // بيلتقط نفس الرقم بأي كتابة (فراغات/حروف/أرقام عربية) بدل ما يعتبرها أرقام مختلفة.
                $result = $this->invoicePayments->recordInvoicePayment(
                    $invoice, $method->type, $method->id, $amount, $executedBy,
                    referenceNumber: $normalizedReference
                );
                $this->updateFinancialState($lockedOrder, $result['invoice']);
            });

        return $this->releaseIfFullyPaid($order, $executedBy);
    }

    public function debitEntityAccountAndRelease(
        Order $order,
        string $entityType,
        int $entityId,
        float $amount,
        string $idempotencyKey,
        int $executedBy,
    ): Order {
        $this->assertCallCenterOrder($order);
        $this->assertInstantDebitEligible($order);
        $this->assertEntityAttached($order, $entityType, $entityId);
        $payload = [
            'order_id' => $order->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'amount' => round($amount, 3),
        ];

        $this->financialPhase($order, 'call-center-entity-debit', $idempotencyKey, $payload, $executedBy,
            function (IdempotencyRecord $record, Order $lockedOrder) use ($entityType, $entityId, $amount, $executedBy) {
                $this->assertEntityAttached($lockedOrder, $entityType, $entityId);
                $entity = $this->lockEntity($entityType, $entityId);
                $available = $this->availableBalance($entityType, $entity);
                if ($available + 0.001 < $amount) {
                    throw new UnprocessableEntityHttpException('الرصيد المتاح للكيان غير كافٍ لإتمام الدفع.');
                }
                $method = PaymentMethod::active()->where('type', $entityType)->where('is_entity', true)->firstOrFail();
                $invoice = $this->invoiceForPayment($lockedOrder, $amount);
                $result = $this->invoicePayments->recordInvoicePayment(
                    $invoice, $method->type, $method->id, $amount, $executedBy, $entityType, $entityId
                );
                $this->updateFinancialState($lockedOrder, $result['invoice']);
            });

        return $this->releaseIfFullyPaid($order, $executedBy);
    }

    private function financialPhase(Order $order, string $scope, string $key, array $payload, int $executedBy, callable $operation): void
    {
        DB::transaction(function () use ($order, $scope, $key, $payload, $executedBy, $operation) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $record = $this->idempotency->lockOrCreate($scope, $key, $payload, $executedBy, Order::class, $lockedOrder->id);
            if ($record->status === 'financial_committed') return;
            $operation($record, $lockedOrder);
            $this->idempotency->markFinancialCommitted($record, ['order_id' => $lockedOrder->id]);
        }, 3);
    }

    private function releasePhase(Order $order, ?int $executedBy): Order
    {
        if ($order->kitchen_release_status === Order::KITCHEN_RELEASE_STATUS_RELEASED) return $order;
        $released = false;
        try {
            // "منفّذ" (executed_at) بيتعلّم جوّا نفس الـtransaction اللي بتنشأ فيها تذاكر الأقسام، وبنعيد
            // قراءة الحالة تحت القفل — عشان تنفيذ يدوي ومجدول بنفس اللحظة ما يطبعوا مرتين.
            $released = DB::transaction(function () use ($order, $executedBy) {
                $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
                if ($lockedOrder->kitchen_release_status === Order::KITCHEN_RELEASE_STATUS_RELEASED) {
                    return false;
                }
                $this->orderConfirmation->release($lockedOrder);
                $lockedOrder->update([
                    'kitchen_release_status' => Order::KITCHEN_RELEASE_STATUS_RELEASED,
                    'kitchen_released_at' => now(),
                    'kitchen_released_by' => $executedBy,
                    'executed_at' => now(),
                    'execution_failed_reason' => null,
                    'status' => 'confirmed',
                ]);

                return true;
            }, 3);
        } catch (\Throwable $exception) {
            Log::error('Call-center kitchen release failed.', [
                'order_id' => $order->id,
                'executed_by' => $executedBy,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
            DB::transaction(function () use ($order) {
                Order::query()->lockForUpdate()->findOrFail($order->id)->update([
                    'kitchen_release_status' => Order::KITCHEN_RELEASE_STATUS_FAILED,
                    'status' => 'pending',
                ]);
            });
        }

        $fresh = $order->fresh();
        if ($released) {
            // بعد الـcommit وبره الـtransaction: فشل طابعة قسم ما بيرجّع التنفيذ ولا بيوقف باقي الأقسام،
            // بيتسجّل على تذكرة القسم (print_status=failed) مع زر إعادة طباعة.
            app(DepartmentTicketPrinter::class)->printOrder($fresh);
        }

        return $fresh;
    }

    private function invoiceForPayment(Order $order, float $amount): \App\Models\Invoice
    {
        $invoice = $order->invoice()->lockForUpdate()->first();
        // الفاتورة بتنشأ هون، جوّا نفس transaction الدفع وبعد فحص الرقم المرجعي — مش بطلب منفصل من الواجهة.
        // هيك لو الدفع فشل (مرجع مكرر مثلًا) ما بتضل فاتورة معلّقة بتفشّل المحاولة الجاية بـ"يوجد فاتورة مسبقة".
        if (! $invoice) {
            app(\App\Services\Invoice\InvoiceFromOrderService::class)->createFromOrder($order, [
                'customer_id' => $order->customer_id,
                'notes' => $order->note,
            ], auth()->id());
            $invoice = $order->invoice()->lockForUpdate()->firstOrFail();
        }
        if ($amount <= 0 || $amount > $invoice->remainingAmount() + 0.001) {
            throw new UnprocessableEntityHttpException('يجب أن يكون مبلغ الدفعة موجبًا وألا يتجاوز المتبقي من الفاتورة.');
        }
        return $invoice;
    }

    private function updateFinancialState(Order $order, \App\Models\Invoice $invoice): void
    {
        $payments = $invoice->payments()->get(['entity_type']);
        $hasEntity = $payments->contains(fn ($payment) => filled($payment->entity_type));
        $hasManual = $payments->contains(fn ($payment) => blank($payment->entity_type));
        $policy = match (true) {
            $hasEntity && $hasManual => Order::PAYMENT_POLICY_MIXED,
            $hasEntity => Order::PAYMENT_POLICY_INSTANT_DEBIT,
            default => Order::PAYMENT_POLICY_MANUAL_CONFIRMATION,
        };
        $fullyPaid = $invoice->fresh()->remainingAmount() <= 0.001;

        $order->update([
            'payment_policy' => $policy,
            'kitchen_release_status' => Order::KITCHEN_RELEASE_STATUS_HELD,
            'status' => 'pending',
        ]);

        // Only the fully-paid case goes through markPaid(); a partial payment
        // is not a payment moment and must not fire OrderPaid.
        //
        // CLOSES_LIFECYCLE is deliberately NOT set. Here payment comes before
        // preparation: full payment is what releases the order to the kitchen,
        // and releasePhase() moves status to 'confirmed' a moment later.
        // Writing 'paid' would erase where the order actually is and be
        // overwritten immediately. status stays the fulfilment state; the
        // financial state lives in payment_status and paid_at, which markPaid()
        // now sets here exactly as it does for POS.
        if ($fullyPaid) {
            $this->orderPayments->markPaid($order, [
                'channel' => 'call_center',
                'invoice_id' => $invoice->id,
            ]);
        } else {
            $order->update(['payment_status' => Order::PAYMENT_STATUS_PROCESSING]);
        }
    }

    private function releaseIfFullyPaid(Order $order, int $executedBy): Order
    {
        $freshOrder = $order->fresh(['invoice']);
        if ($freshOrder->payment_status !== Order::PAYMENT_STATUS_PAID
            || ! $freshOrder->invoice
            || $freshOrder->invoice->remainingAmount() > 0.001) {
            $freshOrder->update([
                'status' => 'pending',
                'kitchen_release_status' => Order::KITCHEN_RELEASE_STATUS_HELD,
            ]);
            return $freshOrder->fresh();
        }

        // طلب مجدول بموعد لسا ما حان: مدفوع بس ما بينبعت للأقسام — السيرفر بينفّذه بوقته
        // (orders:execute-scheduled) أو الموظف بنفّذه فورًا (executeNow).
        if ($freshOrder->scheduled_at && $freshOrder->scheduled_at->isFuture()) {
            $freshOrder->update([
                'status' => 'pending',
                'kitchen_release_status' => Order::KITCHEN_RELEASE_STATUS_HELD,
            ]);
            return $freshOrder->fresh();
        }

        return $this->releasePhase($freshOrder, $executedBy);
    }

    /**
     * تنفيذ فوري لطلب مدفوع: بيرسل الأصناف للأقسام ويطبع تذاكرها. بيشتغل لطلب مجدول قبل موعده (بيلغي
     * الجدولة)، ولطلب فشل إرساله للمطبخ سابقًا (إعادة محاولة). Idempotent: لو تنفّذ قبل، بيرجّعه كما هو.
     */
    public function executeNow(Order $order, ?int $executedBy, bool $clearSchedule = true): Order
    {
        $this->assertCallCenterOrder($order);
        $fresh = $order->fresh();

        if ($fresh->kitchen_release_status === Order::KITCHEN_RELEASE_STATUS_RELEASED) {
            return $fresh;
        }
        if ($fresh->payment_status !== Order::PAYMENT_STATUS_PAID) {
            throw new UnprocessableEntityHttpException('لا يمكن تنفيذ الطلب قبل اكتمال الدفع.');
        }

        if ($clearSchedule && $fresh->scheduled_at) {
            $fresh->update(['scheduled_at' => null]);
            app(OrderStatusService::class)->logStatusChange($fresh, $fresh->status, $fresh->status, 'execution', 'تنفيذ فوري قبل الموعد المجدول');
        }

        return $this->releasePhase($fresh->fresh(), $executedBy);
    }

    /** جدولة تنفيذ الطلب على السيرفر بوقت محدد (أو تعديل الوقت). مسموحة قبل التنفيذ فقط. */
    public function schedule(Order $order, \DateTimeInterface $at, int $by): Order
    {
        $this->assertCallCenterOrder($order);
        $fresh = $order->fresh();

        if ($fresh->kitchen_release_status === Order::KITCHEN_RELEASE_STATUS_RELEASED) {
            throw new UnprocessableEntityHttpException('الطلب تنفّذ بالفعل ولا يمكن جدولته.');
        }
        if ($at <= now()->addSeconds(30)) {
            throw new UnprocessableEntityHttpException('وقت الجدولة لازم يكون بالمستقبل.');
        }

        $fresh->update(['scheduled_at' => $at, 'execution_attempts' => 0, 'execution_failed_reason' => null]);
        app(OrderStatusService::class)->logStatusChange($fresh, $fresh->status, $fresh->status, 'scheduled', 'جدولة التنفيذ: '.\Illuminate\Support\Carbon::instance($at)->format('Y-m-d H:i'));

        return $fresh->fresh();
    }

    /** إلغاء الجدولة — الطلب بيرجع "بانتظار التنفيذ" وبيتنفّذ يدويًا (executeNow). */
    public function unschedule(Order $order, int $by): Order
    {
        $this->assertCallCenterOrder($order);
        $fresh = $order->fresh();

        if ($fresh->scheduled_at) {
            $fresh->update(['scheduled_at' => null]);
            app(OrderStatusService::class)->logStatusChange($fresh, $fresh->status, $fresh->status, 'scheduled', 'إلغاء جدولة التنفيذ');
        }

        return $fresh->fresh();
    }

    private function lockEntity(string $type, int $id): Model
    {
        $class = match ($type) {
            'customer' => Customer::class,
            'employee' => Employee::class,
            'supplier' => Supplier::class,
            default => throw new UnprocessableEntityHttpException('نوع الكيان غير مدعوم.'),
        };
        return $class::query()->lockForUpdate()->findOrFail($id);
    }

    private function availableBalance(string $type, Model $entity): float
    {
        return match ($type) {
            'customer' => max(0, (float) $entity->credit_limit - $this->subledgers->getCustomerBalance($entity->id)),
            'employee' => $this->employeeAvailableBalance($entity->id),
            'supplier' => max(0, $this->subledgers->getSupplierBalance($entity->id)),
        };
    }

    private function employeeAvailableBalance(int $employeeId): float
    {
        $balances = $this->subledgers->getEmployeeBalances($employeeId);
        return max(0, (float) $balances['accrued_salary'] - (float) $balances['outstanding_advance']);
    }

    /**
     * "AB 123" و"ab123" و"AB١٢٣" لازم ينحسبوا نفس الرقم: بنشيل كل الفراغات (بما فيها الفراغ غير
     * القابل للكسر)، وبنحوّل الأرقام العربية-الهندية (٠-٩) والفارسية (۰-۹) لأرقام إنجليزية، وبنكبّر الأحرف.
     */
    public static function normalizeReference(string $reference): string
    {
        $digits = [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ];

        return mb_strtoupper((string) preg_replace('/[\s\x{00A0}\x{200B}-\x{200F}]+/u', '', strtr(trim($reference), $digits)));
    }

    /** رقم الطلب المختصر (#MMDD-XXXX) اللي استخدم هالمرجع قبل، أو null لو المرجع جديد — عالميًا لكل طرق الدفع. */
    private function referenceOwner(string $normalizedReference): ?string
    {
        $orderNumber = PaymentConfirmation::query()
            ->join('orders', 'orders.id', '=', 'payment_confirmations.order_id')
            ->where('payment_confirmations.normalized_reference_number', $normalizedReference)
            ->value('orders.order_number');

        $orderNumber ??= DB::table('payments')
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->join('orders', 'orders.id', '=', 'invoices.order_id')
            ->where('payments.reference_number', $normalizedReference)
            ->value('orders.order_number');

        return $orderNumber ? self::shortOrderRef($orderNumber) : null;
    }

    /** ORD-20270209-0001 → #0209-0001 (نفس اختصار الواجهة getOrderReference) */
    public static function shortOrderRef(string $orderNumber): string
    {
        $parts = explode('-', $orderNumber);
        $sequence = array_pop($parts) ?: $orderNumber;
        $date = array_pop($parts);

        return $date && preg_match('/^\d{8}$/', $date) ? '#'.substr($date, 4).'-'.$sequence : '#'.$sequence;
    }

    private function assertCallCenterOrder(Order $order): void
    {
        if ($order->source !== 'call_center') {
            throw new UnprocessableEntityHttpException('خدمة تنفيذ الكول سنتر لا تقبل طلبات من مصدر آخر.');
        }
        if (in_array($order->status, ['cancelled', 'paid', 'served', 'closed'], true)) {
            throw new UnprocessableEntityHttpException('حالة الطلب الحالية لا تسمح بتنفيذ عملية كول سنتر.');
        }
    }

    private function assertManualConfirmationEligible(Order $order): void
    {
        $retry = $order->payment_status === Order::PAYMENT_STATUS_PAID
            && in_array($order->kitchen_release_status, [
                Order::KITCHEN_RELEASE_STATUS_FAILED,
                Order::KITCHEN_RELEASE_STATUS_RELEASED,
            ], true);
        $partial = $order->payment_status === Order::PAYMENT_STATUS_PROCESSING
            && in_array($order->payment_policy, Order::PAYMENT_POLICIES, true);
        // الطلب الجديد (policy/status لسا فاضيين) بيبدأ بحوالة مباشرة — قبل هيك كان لازم حدا يستدعي
        // saveOrderAwaitingBankConfirmation() أول، وما في أي مسار بيستدعيه، فكان كل طلب جديد "غير مؤهل".
        $initial = in_array($order->payment_policy, [null, Order::PAYMENT_POLICY_MANUAL_CONFIRMATION], true)
            && in_array($order->payment_status, [null, Order::PAYMENT_STATUS_AWAITING_CONFIRMATION, Order::PAYMENT_STATUS_FAILED], true);
        if (! $retry && ! $partial && ! $initial) {
            throw new UnprocessableEntityHttpException('الطلب غير مؤهل لتأكيد تحويل يدوي.');
        }
    }

    private function assertInstantDebitEligible(Order $order): void
    {
        $retry = $order->payment_status === Order::PAYMENT_STATUS_PAID
            && in_array($order->kitchen_release_status, [
                Order::KITCHEN_RELEASE_STATUS_FAILED,
                Order::KITCHEN_RELEASE_STATUS_RELEASED,
            ], true);
        $partial = $order->payment_status === Order::PAYMENT_STATUS_PROCESSING
            && in_array($order->payment_policy, Order::PAYMENT_POLICIES, true);
        $initial = in_array($order->payment_policy, [null, Order::PAYMENT_POLICY_INSTANT_DEBIT], true)
            && in_array($order->payment_status, [null, Order::PAYMENT_STATUS_FAILED], true);
        if (! $retry && ! $partial && ! $initial) {
            throw new UnprocessableEntityHttpException('الطلب غير مؤهل للخصم الفوري.');
        }
    }

    private function assertEntityAttached(Order $order, string $entityType, int $entityId): void
    {
        $attachedId = match ($entityType) {
            'customer' => $order->customer_id,
            'employee' => $order->employee_id,
            'supplier' => $order->supplier_id,
            default => throw new UnprocessableEntityHttpException('نوع الكيان غير مدعوم.'),
        };
        if (! $attachedId || (int) $attachedId !== $entityId) {
            throw new UnprocessableEntityHttpException('الكيان المالي غير مرتبط بهذا الطلب.');
        }
    }
}
