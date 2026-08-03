<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entry;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentConfirmation;
use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\SubledgerService;
use App\Services\CallCenter\CallCenterOrderExecutionService;
use App\Services\Invoice\InvoicePaymentService;
use App\Services\Order\OrderConfirmationService;
use App\Services\Support\IdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Tests\TestCase;

class CallCenterOrderExecutionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_public_operations_reject_non_call_center_orders(): void
    {
        [$order, $user] = $this->callCenterOrder();
        $order->update(['source' => 'pos']);
        $method = $this->paymentMethod('bank');
        $customer = Customer::create(['name' => 'POS Customer', 'code' => uniqid('CUS-'), 'credit_limit' => 500, 'branch_id' => $order->branch_id]);
        $service = $this->service();

        foreach ([
            fn () => $service->saveOrderAwaitingBankConfirmation($order->fresh(), []),
            fn () => $service->confirmBankTransferAndRelease($order->fresh(), 'POS-REF', $method->id, 100, 'pos-transfer', $user->id),
            fn () => $service->debitEntityAccountAndRelease($order->fresh(), 'customer', $customer->id, 100, 'pos-entity', $user->id),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected a non-call-center rejection.');
            } catch (UnprocessableEntityHttpException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('idempotency_records', 0);
    }

    public function test_terminal_order_cannot_return_to_awaiting_confirmation(): void
    {
        [$order] = $this->callCenterOrder();
        $order->update(['status' => 'cancelled']);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->service()->saveOrderAwaitingBankConfirmation($order->fresh(), []);
    }

    public function test_full_transfer_commits_finance_and_releases_kitchen(): void
    {
        [$order, $user] = $this->callCenterOrder();
        $method = $this->paymentMethod('bank');

        $result = $this->service()->confirmBankTransferAndRelease($order, ' BN 100 ', $method->id, 100, 'transfer-1', $user->id);

        $this->assertSame('paid', $result->payment_status);
        $this->assertSame('released', $result->kitchen_release_status);
        $this->assertSame('confirmed', $result->status);
        $this->assertDatabaseCount('production_tickets', 1);
        $this->assertDatabaseHas('idempotency_records', ['key' => 'transfer-1', 'status' => 'financial_committed']);
    }

    public function test_duplicate_reference_for_same_method_is_rejected_without_order_change(): void
    {
        $method = $this->paymentMethod('bank');
        [$first, $user] = $this->callCenterOrder();
        $this->service()->confirmBankTransferAndRelease($first, 'REF-1', $method->id, 100, 'ref-first', $user->id);
        [$second] = $this->callCenterOrder();

        try {
            $this->service()->confirmBankTransferAndRelease($second, ' ref-1 ', $method->id, 100, 'ref-second', $user->id);
            $this->fail('Expected a duplicate-reference conflict.');
        } catch (ConflictHttpException) {
            $this->assertSame('pending', $second->fresh()->status);
            $this->assertSame(Order::PAYMENT_STATUS_AWAITING_CONFIRMATION, $second->fresh()->payment_status);
        }
    }

    public function test_same_reference_with_different_payment_method_is_accepted(): void
    {
        $bank = $this->paymentMethod('bank');
        $wallet = $this->paymentMethod('wallet');
        [$first, $user] = $this->callCenterOrder();
        [$second] = $this->callCenterOrder();

        $this->service()->confirmBankTransferAndRelease($first, 'SHARED-9', $bank->id, 100, 'shared-bank', $user->id);
        $this->service()->confirmBankTransferAndRelease($second, 'SHARED-9', $wallet->id, 100, 'shared-wallet', $user->id);

        $this->assertDatabaseCount('payment_confirmations', 2);
    }

    public function test_repeated_transfer_key_does_not_duplicate_financial_or_kitchen_records(): void
    {
        [$order, $user] = $this->callCenterOrder();
        $method = $this->paymentMethod('bank');
        $service = $this->service();
        $service->confirmBankTransferAndRelease($order, 'IDEM-1', $method->id, 100, 'same-transfer', $user->id);
        $service->confirmBankTransferAndRelease($order->fresh(), 'IDEM-1', $method->id, 100, 'same-transfer', $user->id);

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_confirmations', 1);
        $this->assertDatabaseCount('production_tickets', 1);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_committed_finance_survives_release_failure(): void
    {
        Log::spy();
        [$order, $user] = $this->callCenterOrder();
        $method = $this->paymentMethod('bank');
        $release = Mockery::mock(OrderConfirmationService::class);
        $release->shouldReceive('release')->once()->andThrow(new \RuntimeException('kitchen unavailable'));

        $result = $this->service($release)->confirmBankTransferAndRelease($order, 'FAIL-1', $method->id, 100, 'release-fail', $user->id);

        $this->assertSame('paid', $result->payment_status);
        $this->assertSame('release_failed', $result->kitchen_release_status);
        $this->assertSame('pending', $result->status);
        $this->assertDatabaseHas('idempotency_records', ['key' => 'release-fail', 'status' => 'financial_committed']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_confirmations', 1);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('production_tickets', 0);
    }

    public function test_release_metadata_failure_rolls_back_created_tickets_but_keeps_finance(): void
    {
        Log::spy();
        [$order, $user] = $this->callCenterOrder();
        $method = $this->paymentMethod('bank');
        Order::updating(function (Order $updating): void {
            if ($updating->isDirty('kitchen_release_status')
                && $updating->kitchen_release_status === Order::KITCHEN_RELEASE_STATUS_RELEASED) {
                throw new \RuntimeException('metadata storage unavailable');
            }
        });

        $result = $this->service()->confirmBankTransferAndRelease($order, 'META-FAIL', $method->id, 100, 'metadata-fail', $user->id);

        $this->assertSame('paid', $result->payment_status);
        $this->assertSame('pending', $result->status);
        $this->assertSame('release_failed', $result->kitchen_release_status);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_confirmations', 1);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('production_tickets', 0);
        $this->assertDatabaseCount('production_ticket_items', 0);
    }

    public function test_order_with_item_without_department_is_not_confirmed(): void
    {
        [$order] = $this->callCenterOrder();
        $order->items()->update(['department_id' => null]);
        $order->update(['payment_status' => Order::PAYMENT_STATUS_PAID]);

        $this->expectException(UnprocessableEntityHttpException::class);
        try {
            app(OrderConfirmationService::class)->release($order->fresh());
        } finally {
            $this->assertSame('pending', $order->fresh()->status);
            $this->assertDatabaseCount('production_tickets', 0);
        }
    }

    public function test_retry_after_release_failure_only_retries_release(): void
    {
        Log::spy();
        [$order, $user] = $this->callCenterOrder();
        $method = $this->paymentMethod('bank');
        $release = Mockery::mock(OrderConfirmationService::class);
        $release->shouldReceive('release')->once()->andThrow(new \RuntimeException('first failure'));
        $this->service($release)->confirmBankTransferAndRelease($order, 'RETRY-1', $method->id, 100, 'release-retry', $user->id);

        $result = $this->service()->confirmBankTransferAndRelease($order->fresh(), 'RETRY-1', $method->id, 100, 'release-retry', $user->id);

        $this->assertSame('released', $result->kitchen_release_status);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_confirmations', 1);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_insufficient_balance_is_rejected_for_customer_employee_and_supplier(): void
    {
        foreach (['customer', 'employee', 'supplier'] as $type) {
            [$order, $user] = $this->callCenterOrder();
            $entity = $this->emptyEntity($type, $order->branch_id);
            $this->attachEntity($order, $type, $entity->id);
            $this->paymentMethod($type);
            try {
                $this->service()->debitEntityAccountAndRelease($order, $type, $entity->id, 100, "insufficient-{$type}", $user->id);
                $this->fail("Expected insufficient {$type} balance.");
            } catch (UnprocessableEntityHttpException) {
                $this->assertNull($order->fresh()->payment_status);
                $this->assertDatabaseMissing('payments', ['invoice_id' => $order->invoice->id]);
            }
        }
    }

    public function test_repeated_entity_debit_key_does_not_double_debit(): void
    {
        [$order, $user] = $this->callCenterOrder();
        $customer = Customer::create(['name' => 'Credit Customer', 'code' => uniqid('CUS-'), 'credit_limit' => 500, 'branch_id' => $order->branch_id]);
        $this->attachEntity($order, 'customer', $customer->id);
        $this->paymentMethod('customer');
        $service = $this->service();

        $service->debitEntityAccountAndRelease($order, 'customer', $customer->id, 100, 'entity-once', $user->id);
        $service->debitEntityAccountAndRelease($order->fresh(), 'customer', $customer->id, 100, 'entity-once', $user->id);

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_entity_debit_rejects_entity_not_attached_to_order(): void
    {
        [$order, $user] = $this->callCenterOrder();
        $attached = Customer::create(['name' => 'Attached', 'code' => uniqid('CUS-'), 'credit_limit' => 500, 'branch_id' => $order->branch_id]);
        $other = Customer::create(['name' => 'Other', 'code' => uniqid('CUS-'), 'credit_limit' => 500, 'branch_id' => $order->branch_id]);
        $this->attachEntity($order, 'customer', $attached->id);
        $this->paymentMethod('customer');

        $this->expectException(UnprocessableEntityHttpException::class);
        try {
            $this->service()->debitEntityAccountAndRelease($order->fresh(), 'customer', $other->id, 100, 'wrong-entity', $user->id);
        } finally {
            $this->assertDatabaseCount('payments', 0);
            $this->assertDatabaseCount('transactions', 0);
            $this->assertDatabaseCount('idempotency_records', 0);
        }
    }

    public function test_customer_balance_is_debited_once_across_idempotent_retry(): void
    {
        $this->assertEntityBalanceDebitedOnce('customer');
    }

    public function test_employee_balance_is_debited_once_across_idempotent_retry(): void
    {
        $this->assertEntityBalanceDebitedOnce('employee');
    }

    public function test_supplier_balance_is_debited_once_across_idempotent_retry(): void
    {
        $this->assertEntityBalanceDebitedOnce('supplier');
    }

    public function test_same_key_with_changed_financial_payload_is_rejected(): void
    {
        [$order, $user] = $this->callCenterOrder();
        $method = $this->paymentMethod('bank');
        $this->service()->confirmBankTransferAndRelease($order, 'HASH-1', $method->id, 100, 'hash-key', $user->id);

        $this->expectException(ConflictHttpException::class);
        $this->service()->confirmBankTransferAndRelease($order->fresh(), 'HASH-2', $method->id, 100, 'hash-key', $user->id);
    }

    public function test_mixed_entity_then_transfer_releases_only_after_full_payment(): void
    {
        [$order, $user] = $this->callCenterOrder();
        $customer = Customer::create(['name' => 'Mixed Customer', 'code' => uniqid('CUS-'), 'credit_limit' => 500, 'branch_id' => $order->branch_id]);
        $this->attachEntity($order, 'customer', $customer->id);
        $this->paymentMethod('customer');
        $bank = $this->paymentMethod('bank');

        $partial = $this->service()->debitEntityAccountAndRelease($order->fresh(), 'customer', $customer->id, 40, 'mixed-entity-first', $user->id);
        $this->assertSame(Order::PAYMENT_STATUS_PROCESSING, $partial->payment_status);
        $this->assertSame(Order::PAYMENT_POLICY_INSTANT_DEBIT, $partial->payment_policy);
        $this->assertSame(Order::KITCHEN_RELEASE_STATUS_HELD, $partial->kitchen_release_status);
        $this->assertSame('pending', $partial->status);
        $this->assertDatabaseCount('production_tickets', 0);

        $complete = $this->service()->confirmBankTransferAndRelease($partial, 'MIXED-E-T', $bank->id, 60, 'mixed-transfer-second', $user->id);
        $this->assertSame(Order::PAYMENT_STATUS_PAID, $complete->payment_status);
        $this->assertSame(Order::PAYMENT_POLICY_MIXED, $complete->payment_policy);
        $this->assertSame(Order::KITCHEN_RELEASE_STATUS_RELEASED, $complete->kitchen_release_status);
        $this->assertSame('confirmed', $complete->status);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('production_tickets', 1);
    }

    public function test_mixed_transfer_then_entity_has_same_final_policy_and_release_behavior(): void
    {
        [$order, $user] = $this->callCenterOrder();
        $customer = Customer::create(['name' => 'Reverse Mixed', 'code' => uniqid('CUS-'), 'credit_limit' => 500, 'branch_id' => $order->branch_id]);
        $order->update(['customer_id' => $customer->id]);
        $bank = $this->paymentMethod('bank');
        $this->paymentMethod('customer');

        $partial = $this->service()->confirmBankTransferAndRelease($order->fresh(), 'MIXED-T-E', $bank->id, 35, 'mixed-transfer-first', $user->id);
        $this->assertSame(Order::PAYMENT_STATUS_PROCESSING, $partial->payment_status);
        $this->assertSame(Order::PAYMENT_POLICY_MANUAL_CONFIRMATION, $partial->payment_policy);
        $this->assertSame(Order::KITCHEN_RELEASE_STATUS_HELD, $partial->kitchen_release_status);
        $this->assertSame('pending', $partial->status);
        $this->assertDatabaseCount('production_tickets', 0);

        $complete = $this->service()->debitEntityAccountAndRelease($partial, 'customer', $customer->id, 65, 'mixed-entity-second', $user->id);
        $this->assertSame(Order::PAYMENT_STATUS_PAID, $complete->payment_status);
        $this->assertSame(Order::PAYMENT_POLICY_MIXED, $complete->payment_policy);
        $this->assertSame(Order::KITCHEN_RELEASE_STATUS_RELEASED, $complete->kitchen_release_status);
        $this->assertSame('confirmed', $complete->status);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('production_tickets', 1);
    }

    public function test_second_leg_above_remaining_is_rejected_without_state_change(): void
    {
        [$order, $user] = $this->callCenterOrder();
        $customer = Customer::create(['name' => 'Overpay Mixed', 'code' => uniqid('CUS-'), 'credit_limit' => 500, 'branch_id' => $order->branch_id]);
        $this->attachEntity($order, 'customer', $customer->id);
        $this->paymentMethod('customer');
        $bank = $this->paymentMethod('bank');
        $partial = $this->service()->debitEntityAccountAndRelease($order->fresh(), 'customer', $customer->id, 40, 'overpay-first', $user->id);

        try {
            $this->service()->confirmBankTransferAndRelease($partial, 'OVER-REMAINING', $bank->id, 61, 'overpay-second', $user->id);
            $this->fail('Expected amount above remaining balance to be rejected.');
        } catch (UnprocessableEntityHttpException) {
            $fresh = $order->fresh();
            $this->assertSame(Order::PAYMENT_STATUS_PROCESSING, $fresh->payment_status);
            $this->assertSame(Order::PAYMENT_POLICY_INSTANT_DEBIT, $fresh->payment_policy);
            $this->assertSame(Order::KITCHEN_RELEASE_STATUS_HELD, $fresh->kitchen_release_status);
            $this->assertSame('pending', $fresh->status);
            $this->assertDatabaseCount('payments', 1);
            $this->assertDatabaseCount('payment_confirmations', 0);
            $this->assertDatabaseCount('production_tickets', 0);
        }
    }

    public function test_replaying_partial_leg_does_not_release_kitchen(): void
    {
        [$order, $user] = $this->callCenterOrder();
        $customer = Customer::create(['name' => 'Replay Partial', 'code' => uniqid('CUS-'), 'credit_limit' => 500, 'branch_id' => $order->branch_id]);
        $this->attachEntity($order, 'customer', $customer->id);
        $this->paymentMethod('customer');
        $service = $this->service();

        $service->debitEntityAccountAndRelease($order->fresh(), 'customer', $customer->id, 40, 'partial-replay', $user->id);
        $replayed = $service->debitEntityAccountAndRelease($order->fresh(), 'customer', $customer->id, 40, 'partial-replay', $user->id);

        $this->assertSame(Order::PAYMENT_STATUS_PROCESSING, $replayed->payment_status);
        $this->assertSame(Order::KITCHEN_RELEASE_STATUS_HELD, $replayed->kitchen_release_status);
        $this->assertSame('pending', $replayed->status);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('production_tickets', 0);
    }

    private function service(?OrderConfirmationService $release = null): CallCenterOrderExecutionService
    {
        return new CallCenterOrderExecutionService(
            app(IdempotencyService::class),
            app(InvoicePaymentService::class),
            $release ?? app(OrderConfirmationService::class),
            app(SubledgerService::class),
        );
    }

    private function callCenterOrder(): array
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $item = Item::factory()->create();
        $order = Order::create([
            'order_number' => uniqid('ORD-CC-'), 'branch_id' => $branch->id,
            'order_type' => 'takeaway', 'source' => 'call_center', 'status' => 'pending',
            'payment_policy' => Order::PAYMENT_POLICY_MANUAL_CONFIRMATION,
            'payment_status' => Order::PAYMENT_STATUS_AWAITING_CONFIRMATION,
            'kitchen_release_status' => Order::KITCHEN_RELEASE_STATUS_HELD,
            'subtotal' => 100, 'total' => 100,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'item_id' => $item->id, 'department_id' => $item->department_id,
            'item_name' => $item->name, 'quantity' => 1, 'price' => 100, 'total' => 100, 'status' => 'pending',
        ]);
        Invoice::create([
            'number' => uniqid('INV-'), 'order_id' => $order->id, 'branch_id' => $branch->id,
            'status' => 'draft', 'subtotal' => 100, 'discount' => 0, 'total' => 100, 'invoice_date' => now(),
        ]);
        $this->account('4', 'revenue', false);
        return [$order, $user];
    }

    private function paymentMethod(string $type): PaymentMethod
    {
        $accountType = $type === 'supplier' ? 'liability' : 'asset';
        $accountCode = match ($type) {
            'customer' => '1120', 'employee' => '2120', 'supplier' => '2110',
            default => 'TEST-'.strtoupper($type).'-'.uniqid(),
        };
        if (in_array($type, ['employee', 'supplier'], true)) $accountType = 'liability';
        $account = $this->account($accountCode, $accountType);
        return PaymentMethod::create([
            'name' => $type, 'type' => $type, 'account_id' => $account->id,
            'is_active' => true, 'is_entity' => in_array($type, ['customer', 'employee', 'supplier'], true),
        ]);
    }

    private function account(string $code, string $type, bool $postable = true): Account
    {
        return Account::firstOrCreate(['code' => $code], [
            'name' => $code, 'type' => $type, 'normal_balance' => in_array($type, ['asset', 'expense']) ? 'debit' : 'credit',
            'allow_posting' => $postable, 'is_active' => true,
        ]);
    }

    private function emptyEntity(string $type, int $branchId): Customer|Employee|Supplier
    {
        return match ($type) {
            'customer' => Customer::create(['name' => 'No Credit', 'code' => uniqid('CUS-'), 'credit_limit' => 0, 'branch_id' => $branchId]),
            'employee' => Employee::factory()->create(['branch_id' => $branchId]),
            'supplier' => Supplier::create(['name' => 'No Balance', 'code' => uniqid('SUP-'), 'branch_id' => $branchId]),
        };
    }

    private function attachEntity(Order $order, string $type, int $id): void
    {
        $order->update([
            'customer_id' => $type === 'customer' ? $id : null,
            'employee_id' => $type === 'employee' ? $id : null,
            'supplier_id' => $type === 'supplier' ? $id : null,
            'payment_policy' => Order::PAYMENT_POLICY_INSTANT_DEBIT,
            'payment_status' => null,
        ]);
    }

    private function assertEntityBalanceDebitedOnce(string $type): void
    {
        [$order, $user] = $this->callCenterOrder();
        $entity = match ($type) {
            'customer' => Customer::create(['name' => 'Credit', 'code' => uniqid('CUS-'), 'credit_limit' => 500, 'branch_id' => $order->branch_id]),
            'employee' => Employee::factory()->create(['branch_id' => $order->branch_id]),
            'supplier' => Supplier::create(['name' => 'Payable', 'code' => uniqid('SUP-'), 'branch_id' => $order->branch_id]),
        };
        $this->attachEntity($order, $type, $entity->id);
        $this->paymentMethod($type);
        if ($type !== 'customer') $this->seedEntityPayable($type, $entity->id, $order->branch_id, 500);

        $before = $this->availableBalanceFor($type, $entity->fresh());
        $service = $this->service();
        $service->debitEntityAccountAndRelease($order->fresh(), $type, $entity->id, 100, "balance-{$type}", $user->id);
        $afterFirst = $this->availableBalanceFor($type, $entity->fresh());
        $service->debitEntityAccountAndRelease($order->fresh(), $type, $entity->id, 100, "balance-{$type}", $user->id);
        $afterRetry = $this->availableBalanceFor($type, $entity->fresh());

        $this->assertEqualsWithDelta(500, $before, 0.001);
        $this->assertEqualsWithDelta(400, $afterFirst, 0.001);
        $this->assertEqualsWithDelta($afterFirst, $afterRetry, 0.001);
    }

    private function availableBalanceFor(string $type, Customer|Employee|Supplier $entity): float
    {
        $subledgers = app(SubledgerService::class);
        return match ($type) {
            'customer' => (float) $entity->credit_limit - $subledgers->getCustomerBalance($entity->id),
            'employee' => (function () use ($subledgers, $entity) {
                $balances = $subledgers->getEmployeeBalances($entity->id);
                return (float) $balances['accrued_salary'] - (float) $balances['outstanding_advance'];
            })(),
            'supplier' => $subledgers->getSupplierBalance($entity->id),
        };
    }

    private function seedEntityPayable(string $type, int $entityId, int $branchId, float $amount): void
    {
        $account = $this->account($type === 'employee' ? '2120' : '2110', 'liability');
        $transaction = Transaction::create([
            'transaction_number' => uniqid('OPEN-'), 'date' => now(), 'type' => 'journal',
            'status' => 'posted', 'description' => 'Opening payable', 'branch_id' => $branchId,
        ]);
        Entry::create([
            'transaction_id' => $transaction->id, 'account_id' => $account->id,
            'debit' => 0, 'credit' => $amount, 'description' => 'Opening payable',
            'subledger_type' => $type, 'subledger_id' => $entityId,
        ]);
    }
}
