<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentConfirmation;
use App\Models\PaymentMethod;
use App\Models\ProductionTicket;
use App\Models\User;
use App\Services\CallCenter\CallCenterOrderExecutionService;
use App\Services\CallCenter\OrderFlowService;
use App\Services\Printing\OrderPrintingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CallCenterOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $manager;
    private PaymentMethod $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        $this->manager = User::factory()->create(['branch_id' => $this->branch->id]);
        Role::findOrCreate('call-center-manager', 'web');
        $this->manager->assignRole('call-center-manager');
        $this->account('4', 'revenue', false);
        $this->bank = $this->paymentMethod('bank', $this->account('FLOW-BANK', 'asset'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_reference_normalization_collapses_case_spaces_and_arabic_digits(): void
    {
        foreach (['ab 123', 'AB١٢٣', ' Ab۱۲۳ ', "AB\u{00A0}123"] as $variant) {
            $this->assertSame('AB123', CallCenterOrderExecutionService::normalizeReference($variant));
        }
        $this->assertSame('#0209-0001', CallCenterOrderExecutionService::shortOrderRef('ORD-20270209-0001'));
    }

    public function test_fresh_order_can_be_paid_by_transfer_and_is_executed_with_evidence(): void
    {
        $order = $this->orderWithInvoice();

        $response = $this->pay($order, 'TRF 1001', 100, ['bank_name' => 'بنك فلسطين', 'transferred_at' => now()->subDay()->toDateString()]);

        $response->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.kitchen_release_status', 'released')
            ->assertJsonPath('data.warnings', []);
        $confirmation = PaymentConfirmation::firstOrFail();
        $this->assertSame('TRF1001', $confirmation->normalized_reference_number);
        $this->assertSame('بنك فلسطين', $confirmation->bank_name);
        $this->assertSame(now()->subDay()->toDateString(), $confirmation->transferred_at->toDateString());
        $this->assertNotNull($order->fresh()->executed_at);
        $this->assertSame(1, ProductionTicket::where('order_id', $order->id)->count());
    }

    public function test_reference_is_unique_across_orders_and_payment_methods_and_names_the_owner(): void
    {
        $first = $this->orderWithInvoice();
        $this->pay($first, 'AB 123', 100)->assertOk();

        $otherBank = $this->paymentMethod('bank', $this->account('FLOW-BANK-2', 'asset'));
        $second = $this->orderWithInvoice();

        $this->pay($second, 'ab١٢٣', 100, [], $otherBank)
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'الرقم المرجعي مستخدم مسبقًا بالطلب '.CallCenterOrderExecutionService::shortOrderRef($first->order_number).'.']);

        $this->assertSame(1, PaymentConfirmation::count());
    }

    public function test_amount_mismatch_is_a_warning_not_a_rejection(): void
    {
        $order = $this->orderWithInvoice();

        $this->pay($order, 'PART-1', 40)
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'processing')
            ->assertJsonPath('data.warnings.0', 'المبلغ المدخل (40.00) لا يطابق المتبقي على الطلب (100.00).');
        $this->assertNull($order->fresh()->executed_at, 'a partial payment never releases the order');
    }

    public function test_scheduled_paid_order_waits_then_the_server_executes_it_on_time(): void
    {
        $order = $this->orderWithInvoice();
        $order->update(['scheduled_at' => now()->addHour()]);

        $this->pay($order, 'SCHED-1', 100)->assertOk();
        $order = $order->fresh();
        $this->assertSame('scheduled', OrderFlowService::executionStatus($order));
        $this->assertSame(Order::KITCHEN_RELEASE_STATUS_HELD, $order->kitchen_release_status);
        $this->assertSame(0, ProductionTicket::where('order_id', $order->id)->count());

        $this->artisan('orders:execute-scheduled')->assertExitCode(0);
        $this->assertNull($order->fresh()->executed_at, 'not due yet');

        Carbon::setTestNow(now()->addHours(2));
        $this->artisan('orders:execute-scheduled')->assertExitCode(0);

        $order = $order->fresh();
        $this->assertNotNull($order->executed_at);
        $this->assertSame(Order::KITCHEN_RELEASE_STATUS_RELEASED, $order->kitchen_release_status);
        $this->assertSame(1, ProductionTicket::where('order_id', $order->id)->count());

        $this->artisan('orders:execute-scheduled')->assertExitCode(0);
        $this->assertSame(1, ProductionTicket::where('order_id', $order->id)->count(), 'never executes twice');
    }

    public function test_execute_now_before_the_scheduled_time_clears_the_schedule_and_is_idempotent(): void
    {
        $order = $this->orderWithInvoice();
        $order->update(['scheduled_at' => now()->addHour()]);
        $this->pay($order, 'SCHED-2', 100)->assertOk();

        $this->actingAs($this->manager)->postJson("/api/call-center/orders/{$order->id}/execute")
            ->assertOk()->assertJsonPath('data.execution_status', 'executed')->assertJsonPath('data.scheduled_at', null);
        $this->actingAs($this->manager)->postJson("/api/call-center/orders/{$order->id}/execute")->assertOk();

        $this->assertSame(1, ProductionTicket::where('order_id', $order->id)->count());
    }

    public function test_execute_is_rejected_for_unpaid_orders(): void
    {
        $order = $this->orderWithInvoice();

        $this->actingAs($this->manager)->postJson("/api/call-center/orders/{$order->id}/execute")
            ->assertStatus(422);
    }

    public function test_schedule_can_be_set_changed_and_cancelled_but_not_in_the_past(): void
    {
        $order = $this->orderWithInvoice();
        $future = now()->addHours(3)->toIso8601String();

        $this->actingAs($this->manager)->putJson("/api/call-center/orders/{$order->id}/schedule", ['scheduled_at' => $future])
            ->assertOk()->assertJsonPath('data.execution_status', 'scheduled');
        $this->actingAs($this->manager)->putJson("/api/call-center/orders/{$order->id}/schedule", ['scheduled_at' => now()->subMinute()->toIso8601String()])
            ->assertStatus(422);
        $this->actingAs($this->manager)->deleteJson("/api/call-center/orders/{$order->id}/schedule")
            ->assertOk()->assertJsonPath('data.execution_status', 'pending');
    }

    public function test_a_failing_department_printer_is_recorded_and_can_be_reprinted(): void
    {
        config(['call-center.print_on_execute' => true]);
        $attempts = 0;
        $this->mock(OrderPrintingService::class, function ($mock) use (&$attempts) {
            $mock->shouldReceive('printTicket')->andReturnUsing(function () use (&$attempts) {
                return ++$attempts === 1
                    ? ['success' => false, 'message' => 'طابعة القسم مطفية']
                    : ['success' => true];
            });
        });
        $order = $this->orderWithInvoice();

        $this->pay($order, 'PRINT-1', 100)->assertOk();

        $ticket = ProductionTicket::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('failed', $ticket->print_status);
        $this->assertSame('طابعة القسم مطفية', $ticket->print_error);
        $this->assertNotNull($order->fresh()->executed_at, 'a printer failure never rolls back the execution');

        $this->actingAs($this->manager)->postJson("/api/call-center/orders/{$order->id}/tickets/{$ticket->id}/reprint")
            ->assertOk()->assertJsonPath('data.tickets.0.print_status', 'printed');
        $this->assertSame(2, $ticket->fresh()->print_attempts);
    }

    private function orderWithInvoice(): Order
    {
        $item = Item::factory()->create();
        $order = Order::create([
            'order_number' => 'ORD-'.now()->format('Ymd').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'branch_id' => $this->branch->id, 'call_center_agent_id' => $this->manager->id, 'order_type' => 'takeaway',
            'source' => 'call_center', 'status' => 'pending', 'subtotal' => 100, 'total' => 100,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'item_id' => $item->id, 'department_id' => $item->department_id,
            'item_name' => $item->name, 'quantity' => 1, 'price' => 100, 'total' => 100, 'status' => 'pending',
        ]);
        Invoice::create([
            'number' => uniqid('INV-FLOW-'), 'order_id' => $order->id, 'branch_id' => $this->branch->id,
            'status' => 'draft', 'subtotal' => 100, 'discount' => 0, 'total' => 100, 'invoice_date' => now(),
        ]);

        return $order;
    }

    private function pay(Order $order, string $reference, float $amount, array $extra = [], ?PaymentMethod $method = null)
    {
        return $this->actingAs($this->manager)->postJson("/api/call-center/orders/{$order->id}/confirm-transfer", [
            'reference_number' => $reference,
            'payment_method_id' => ($method ?? $this->bank)->id,
            'amount' => $amount,
            'idempotency_key' => uniqid('idem-'),
        ] + $extra);
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
            'name' => $type.'-'.$account->code, 'type' => $type, 'account_id' => $account->id,
            'is_active' => true, 'is_entity' => false,
        ]);
    }
}
