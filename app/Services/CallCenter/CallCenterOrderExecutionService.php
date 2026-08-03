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
    ): Order {
        $this->assertCallCenterOrder($order);
        $this->assertManualConfirmationEligible($order);
        $normalizedReference = $this->normalizeReference($referenceNumber);
        $payload = [
            'order_id' => $order->id,
            'reference_number' => $normalizedReference,
            'payment_method_id' => $paymentMethodId,
            'amount' => round($amount, 3),
        ];

        $this->financialPhase($order, 'call-center-transfer', $idempotencyKey, $payload, $executedBy,
            function (IdempotencyRecord $record, Order $lockedOrder) use ($referenceNumber, $normalizedReference, $paymentMethodId, $amount, $idempotencyKey, $executedBy) {
                $method = PaymentMethod::active()->whereKey($paymentMethodId)->firstOrFail();
                if ($method->is_entity || ! in_array($method->type, ['bank', 'card', 'wallet'], true)) {
                    throw new UnprocessableEntityHttpException('طريقة الدفع لا تدعم التأكيد اليدوي.');
                }
                if (PaymentConfirmation::where('payment_method_id', $paymentMethodId)
                    ->where('normalized_reference_number', $normalizedReference)->exists()) {
                    throw new ConflictHttpException('مرجع التحويل مستخدم مسبقًا لطريقة الدفع نفسها.');
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
                ]);
                $result = $this->invoicePayments->recordInvoicePayment(
                    $invoice, $method->type, $method->id, $amount, $executedBy,
                    referenceNumber: $referenceNumber
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

    private function releasePhase(Order $order, int $executedBy): Order
    {
        if ($order->kitchen_release_status === Order::KITCHEN_RELEASE_STATUS_RELEASED) return $order;
        try {
            DB::transaction(function () use ($order, $executedBy) {
                $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
                $this->orderConfirmation->release($lockedOrder);
                $lockedOrder->update([
                    'kitchen_release_status' => Order::KITCHEN_RELEASE_STATUS_RELEASED,
                    'kitchen_released_at' => now(),
                    'kitchen_released_by' => $executedBy,
                    'status' => 'confirmed',
                ]);
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
        return $order->fresh();
    }

    private function invoiceForPayment(Order $order, float $amount): \App\Models\Invoice
    {
        $invoice = $order->invoice()->lockForUpdate()->first();
        if (! $invoice) throw new UnprocessableEntityHttpException('يجب إنشاء فاتورة Draft للطلب قبل تنفيذ الدفع.');
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
            'payment_status' => $fullyPaid ? Order::PAYMENT_STATUS_PAID : Order::PAYMENT_STATUS_PROCESSING,
            'kitchen_release_status' => Order::KITCHEN_RELEASE_STATUS_HELD,
            'status' => 'pending',
        ]);
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

        return $this->releasePhase($freshOrder, $executedBy);
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

    private function normalizeReference(string $reference): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/', '', trim($reference)));
    }

    private function assertCallCenterOrder(Order $order): void
    {
        if ($order->source !== 'call_center') {
            throw new UnprocessableEntityHttpException('خدمة تنفيذ الكول سنتر لا تقبل طلبات من مصدر آخر.');
        }
        if (in_array($order->status, ['cancelled', 'paid', 'served'], true)) {
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
        $initial = $order->payment_policy === Order::PAYMENT_POLICY_MANUAL_CONFIRMATION
            && $order->payment_status === Order::PAYMENT_STATUS_AWAITING_CONFIRMATION;
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
