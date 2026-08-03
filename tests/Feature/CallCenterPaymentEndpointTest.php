<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CallCenterPaymentEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirm_transfer_double_http_submission_is_idempotent(): void
    {
        [$order, $user] = $this->callCenterOrder();
        $method = $this->paymentMethod('bank', $this->account('HTTP-BANK', 'asset'));
        $payload = [
            'reference_number' => 'HTTP-TRANSFER-1',
            'payment_method_id' => $method->id,
            'amount' => 100,
            'idempotency_key' => 'http-transfer-double',
        ];

        $first = $this->actingAs($user)->postJson("/api/call-center/orders/{$order->id}/confirm-transfer", $payload);
        $second = $this->actingAs($user)->postJson("/api/call-center/orders/{$order->id}/confirm-transfer", $payload);

        $first->assertOk()->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.kitchen_release_status', 'released');
        $second->assertOk();
        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertDatabaseCount('payment_confirmations', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('production_tickets', 1);
        $this->assertDatabaseCount('production_ticket_items', 1);
    }

    public function test_debit_entity_double_http_submission_is_idempotent(): void
    {
        [$order, $user] = $this->callCenterOrder();
        $customer = Customer::create([
            'name' => 'HTTP Credit Customer', 'code' => uniqid('CUS-'),
            'credit_limit' => 500, 'branch_id' => $order->branch_id,
        ]);
        $order->update([
            'customer_id' => $customer->id,
            'payment_policy' => Order::PAYMENT_POLICY_INSTANT_DEBIT,
            'payment_status' => null,
        ]);
        $this->paymentMethod('customer', $this->account('1120', 'asset'));
        $payload = [
            'entity_type' => 'customer',
            'entity_id' => $customer->id,
            'amount' => 100,
            'idempotency_key' => 'http-entity-double',
        ];

        $first = $this->actingAs($user)->postJson("/api/call-center/orders/{$order->id}/debit-entity", $payload);
        $second = $this->actingAs($user)->postJson("/api/call-center/orders/{$order->id}/debit-entity", $payload);

        $first->assertOk()->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.kitchen_release_status', 'released');
        $second->assertOk();
        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertDatabaseCount('payment_confirmations', 0);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('production_tickets', 1);
        $this->assertDatabaseCount('production_ticket_items', 1);
    }

    public function test_http_error_statuses_match_service_failures(): void
    {
        [$firstOrder, $user] = $this->callCenterOrder();
        $method = $this->paymentMethod('bank', $this->account('HTTP-ERROR-BANK', 'asset'));
        $base = ['reference_number' => 'DUP-HTTP', 'payment_method_id' => $method->id, 'amount' => 100];
        $this->actingAs($user)->postJson("/api/call-center/orders/{$firstOrder->id}/confirm-transfer", $base + ['idempotency_key' => 'http-error-first'])->assertOk();

        [$secondOrder] = $this->callCenterOrderFor($user);
        $this->actingAs($user)->postJson("/api/call-center/orders/{$secondOrder->id}/confirm-transfer", $base + ['idempotency_key' => 'http-error-second'])
            ->assertStatus(409);

        $this->actingAs($user)->postJson("/api/call-center/orders/{$firstOrder->id}/confirm-transfer", [
            'reference_number' => 'CHANGED-HTTP', 'payment_method_id' => $method->id,
            'amount' => 100, 'idempotency_key' => 'http-error-first',
        ])->assertStatus(409);

        [$debitOrder] = $this->callCenterOrderFor($user);
        $customer = Customer::create(['name' => 'No Credit', 'code' => uniqid('CUS-'), 'credit_limit' => 0, 'branch_id' => $debitOrder->branch_id]);
        $debitOrder->update(['customer_id' => $customer->id, 'payment_policy' => Order::PAYMENT_POLICY_INSTANT_DEBIT, 'payment_status' => null]);
        $this->paymentMethod('customer', $this->account('1120', 'asset'));
        $this->actingAs($user)->postJson("/api/call-center/orders/{$debitOrder->id}/debit-entity", [
            'entity_type' => 'customer', 'entity_id' => $customer->id, 'amount' => 100,
            'idempotency_key' => 'http-insufficient',
        ])->assertStatus(422);
    }

    public function test_financial_success_with_release_failure_returns_http_200_and_release_failed(): void
    {
        Log::spy();
        [$order, $user] = $this->callCenterOrder();
        $method = $this->paymentMethod('bank', $this->account('HTTP-FAIL-BANK', 'asset'));
        Order::updating(function (Order $updating): void {
            if ($updating->isDirty('kitchen_release_status')
                && $updating->kitchen_release_status === Order::KITCHEN_RELEASE_STATUS_RELEASED) {
                throw new \RuntimeException('metadata unavailable');
            }
        });

        $this->actingAs($user)->postJson("/api/call-center/orders/{$order->id}/confirm-transfer", [
            'reference_number' => 'HTTP-RELEASE-FAIL', 'payment_method_id' => $method->id,
            'amount' => 100, 'idempotency_key' => 'http-release-fail',
        ])->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.kitchen_release_status', 'release_failed');

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('production_tickets', 0);
    }

    public function test_policy_allows_another_call_center_agent_and_records_actual_executor(): void
    {
        [$order] = $this->callCenterOrder();
        $creatorId = $order->call_center_agent_id;
        $other = User::factory()->create(['branch_id' => $order->branch_id]);
        Role::findOrCreate('call-center', 'web');
        $other->assignRole('call-center');
        $method = $this->paymentMethod('bank', $this->account('HTTP-POLICY-BANK', 'asset'));

        $this->actingAs($other)->postJson("/api/call-center/orders/{$order->id}/confirm-transfer", [
            'reference_number' => 'HTTP-FORBIDDEN', 'payment_method_id' => $method->id,
            'amount' => 100, 'idempotency_key' => 'http-forbidden',
        ])->assertOk();

        $this->assertSame($creatorId, $order->fresh()->call_center_agent_id);
        $this->assertSame($other->id, $order->fresh()->kitchen_released_by);
        $this->assertDatabaseHas('payment_confirmations', ['order_id' => $order->id, 'confirmed_by' => $other->id]);
        $this->assertDatabaseHas('payments', ['invoice_id' => $order->invoice->id, 'user_id' => $other->id]);
    }

    public function test_mixed_legs_record_each_executor_and_completion_executor_releases_kitchen(): void
    {
        [$order, $firstExecutor] = $this->callCenterOrder();
        $creatorId = $order->call_center_agent_id;
        $secondExecutor = User::factory()->create(['branch_id' => $order->branch_id]);
        Role::findOrCreate('call-center', 'web');
        $secondExecutor->assignRole('call-center');
        $customer = Customer::create(['name' => 'Two Agents', 'code' => uniqid('CUS-'), 'credit_limit' => 500, 'branch_id' => $order->branch_id]);
        $order->update([
            'customer_id' => $customer->id,
            'payment_policy' => Order::PAYMENT_POLICY_INSTANT_DEBIT,
            'payment_status' => null,
        ]);
        $this->paymentMethod('customer', $this->account('1120', 'asset'));
        $bank = $this->paymentMethod('bank', $this->account('HTTP-TWO-AGENT-BANK', 'asset'));

        $this->actingAs($firstExecutor)->postJson("/api/call-center/orders/{$order->id}/debit-entity", [
            'entity_type' => 'customer', 'entity_id' => $customer->id,
            'amount' => 30, 'idempotency_key' => 'agent-a-leg',
        ])->assertOk()
            ->assertJsonPath('message', 'تم تسجيل الدفعة الجزئية، وما زال الطلب بانتظار استكمال الدفع.')
            ->assertJsonPath('data.invoice_status', 'partial')
            ->assertJsonPath('data.paid_amount', 30)
            ->assertJsonPath('data.remaining_amount', 70)
            ->assertJsonPath('data.payment_status', 'processing')
            ->assertJsonPath('data.payment_policy', 'instant_debit')
            ->assertJsonPath('data.kitchen_release_status', 'held')
            ->assertJsonPath('data.order_status', 'pending');

        $this->actingAs($secondExecutor)->postJson("/api/call-center/orders/{$order->id}/confirm-transfer", [
            'reference_number' => 'AGENT-B-TRANSFER', 'payment_method_id' => $bank->id,
            'amount' => 70, 'idempotency_key' => 'agent-b-leg',
        ])->assertOk()
            ->assertJsonPath('message', 'تم اكتمال الدفع وإرسال الطلب للمطبخ.')
            ->assertJsonPath('data.invoice_status', 'paid')
            ->assertJsonPath('data.paid_amount', 100)
            ->assertJsonPath('data.remaining_amount', 0)
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payment_policy', 'mixed')
            ->assertJsonPath('data.kitchen_release_status', 'released')
            ->assertJsonPath('data.order_status', 'confirmed');

        $payments = $order->invoice->payments()->orderBy('id')->get();
        $this->assertSame($firstExecutor->id, $payments[0]->user_id);
        $this->assertSame($secondExecutor->id, $payments[1]->user_id);
        $this->assertDatabaseHas('payment_confirmations', ['order_id' => $order->id, 'confirmed_by' => $secondExecutor->id]);
        $this->assertSame($secondExecutor->id, $order->fresh()->kitchen_released_by);
        $this->assertSame($creatorId, $order->fresh()->call_center_agent_id);
    }

    private function callCenterOrder(): array
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        Role::findOrCreate('call-center', 'web');
        $user->assignRole('call-center');
        return $this->callCenterOrderFor($user);
    }

    private function callCenterOrderFor(User $user): array
    {
        $branch = $user->branch;
        $item = Item::factory()->create();
        $order = Order::create([
            'order_number' => uniqid('ORD-HTTP-'), 'branch_id' => $branch->id,
            'call_center_agent_id' => $user->id, 'order_type' => 'takeaway',
            'source' => 'call_center', 'status' => 'pending', 'subtotal' => 100, 'total' => 100,
            'payment_policy' => Order::PAYMENT_POLICY_MANUAL_CONFIRMATION,
            'payment_status' => Order::PAYMENT_STATUS_AWAITING_CONFIRMATION,
            'kitchen_release_status' => Order::KITCHEN_RELEASE_STATUS_HELD,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'item_id' => $item->id, 'department_id' => $item->department_id,
            'item_name' => $item->name, 'quantity' => 1, 'price' => 100, 'total' => 100, 'status' => 'pending',
        ]);
        Invoice::create([
            'number' => uniqid('INV-HTTP-'), 'order_id' => $order->id, 'branch_id' => $branch->id,
            'status' => 'draft', 'subtotal' => 100, 'discount' => 0, 'total' => 100, 'invoice_date' => now(),
        ]);
        $this->account('4', 'revenue', false);

        return [$order, $user];
    }

    private function account(string $code, string $type, bool $postable = true): Account
    {
        return Account::firstOrCreate(['code' => $code], [
            'name' => $code, 'type' => $type,
            'normal_balance' => in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit',
            'allow_posting' => $postable, 'is_active' => true,
        ]);
    }

    private function paymentMethod(string $type, Account $account): PaymentMethod
    {
        return PaymentMethod::create([
            'name' => $type, 'type' => $type, 'account_id' => $account->id,
            'is_active' => true, 'is_entity' => $type === 'customer',
        ]);
    }
}
