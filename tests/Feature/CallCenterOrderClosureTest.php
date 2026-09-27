<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ExternalApiClient;
use App\Models\ExternalWebhookDelivery;
use App\Models\IntegrationOutbox;
use App\Models\Order;
use App\Models\OrderActivityLog;
use App\Models\OrderSlot;
use App\Models\User;
use App\Services\CallCenter\OrderFlowService;
use App\Services\Integration\TakeawayDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CallCenterOrderClosureTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        $this->manager = User::factory()->create(['branch_id' => $this->branch->id]);
        Role::findOrCreate('call-center-manager', 'web');
        $this->manager->assignRole('call-center-manager');
    }

    public function test_flow_state_is_derived_from_existing_columns(): void
    {
        $order = $this->order();
        $this->assertSame(
            ['lifecycle' => 'open', 'payment_state' => 'unpaid', 'execution_status' => 'pending', 'ready_to_close' => false],
            array_intersect_key(OrderFlowService::describe($order), array_flip(['lifecycle', 'payment_state', 'execution_status', 'ready_to_close'])),
        );

        $scheduled = $this->order(['scheduled_at' => now()->addHour(), 'payment_status' => Order::PAYMENT_STATUS_PAID]);
        $flow = OrderFlowService::describe($scheduled);
        $this->assertSame('scheduled', $flow['execution_status']);
        $this->assertFalse($flow['ready_to_close']);
        $this->assertSame(['الطلب مجدول ولم يُنفَّذ بعد'], $flow['close_blockers']);

        $ready = $this->order(['payment_status' => Order::PAYMENT_STATUS_PAID, 'kitchen_release_status' => Order::KITCHEN_RELEASE_STATUS_RELEASED]);
        $this->assertTrue(OrderFlowService::describe($ready)['ready_to_close']);
    }

    public function test_close_is_rejected_until_paid_and_executed_and_says_what_is_missing(): void
    {
        $order = $this->order();

        $this->actingAs($this->manager)->postJson("/api/call-center/orders/{$order->id}/close")
            ->assertStatus(422)
            ->assertJsonPath('errors.order.0', 'لا يمكن إغلاق الطلب: الدفع غير مكتمل والطلب لم يُنفَّذ بعد.');

        $order->update(['payment_status' => Order::PAYMENT_STATUS_PAID]);
        $this->actingAs($this->manager)->postJson("/api/call-center/orders/{$order->id}/close")
            ->assertStatus(422)
            ->assertJsonPath('errors.order.0', 'لا يمكن إغلاق الطلب: الطلب لم يُنفَّذ بعد.');

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_close_succeeds_when_paid_and_executed_releases_slot_and_logs(): void
    {
        $order = $this->order(['payment_status' => Order::PAYMENT_STATUS_PAID, 'kitchen_release_status' => Order::KITCHEN_RELEASE_STATUS_RELEASED, 'status' => 'confirmed']);
        $this->assertNotNull(OrderSlot::where('order_id', $order->id)->whereNull('released_at')->first());

        $this->actingAs($this->manager)->postJson("/api/call-center/orders/{$order->id}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.lifecycle', 'closed');

        $fresh = $order->fresh();
        $this->assertSame('closed', $fresh->status);
        $this->assertSame($this->manager->id, (int) $fresh->closed_by);
        $this->assertNotNull($fresh->closed_at);
        $this->assertNull(OrderSlot::where('order_id', $order->id)->whereNull('released_at')->first());
        $this->assertSame(1, OrderActivityLog::where('order_id', $order->id)->where('action_type', 'closed')->count());
    }

    public function test_closing_twice_is_idempotent_and_cancelled_orders_cannot_close(): void
    {
        $order = $this->order(['payment_status' => Order::PAYMENT_STATUS_PAID, 'kitchen_release_status' => Order::KITCHEN_RELEASE_STATUS_RELEASED]);
        $this->actingAs($this->manager)->postJson("/api/call-center/orders/{$order->id}/close")->assertOk();
        $this->actingAs($this->manager)->postJson("/api/call-center/orders/{$order->id}/close")->assertOk();
        $this->assertSame(1, OrderActivityLog::where('order_id', $order->id)->where('action_type', 'closed')->count());

        $cancelled = $this->order(['status' => 'cancelled']);
        $this->actingAs($this->manager)->postJson("/api/call-center/orders/{$cancelled->id}/close")->assertStatus(422);
    }

    public function test_plain_agent_without_permission_cannot_close(): void
    {
        $agent = User::factory()->create(['branch_id' => $this->branch->id]);
        Role::findOrCreate('call-center', 'web');
        $agent->assignRole('call-center');
        $order = $this->order(['payment_status' => Order::PAYMENT_STATUS_PAID, 'kitchen_release_status' => Order::KITCHEN_RELEASE_STATUS_RELEASED]);

        $this->actingAs($agent)->postJson("/api/call-center/orders/{$order->id}/close")->assertForbidden();
        $this->assertNotSame('closed', $order->fresh()->status);
    }

    public function test_slots_endpoint_exposes_flow_fields(): void
    {
        $order = $this->order(['payment_status' => Order::PAYMENT_STATUS_PAID, 'kitchen_release_status' => Order::KITCHEN_RELEASE_STATUS_RELEASED]);

        $row = $this->actingAs($this->manager)->getJson('/api/call-center/slots')
            ->assertOk()
            ->json('data.slots.0.order');

        $this->assertSame($order->id, $row['id']);
        $this->assertTrue($row['ready_to_close']);
        $this->assertSame('paid', $row['payment_state']);
        $this->assertSame('executed', $row['execution_status']);
    }

    public function test_closing_records_one_takeaway_event_with_the_order_details(): void
    {
        $order = $this->order(['payment_status' => Order::PAYMENT_STATUS_PAID, 'kitchen_release_status' => Order::KITCHEN_RELEASE_STATUS_RELEASED, 'customer_name' => 'أحمد', 'customer_phone' => '0599000000']);

        $this->actingAs($this->manager)->postJson("/api/call-center/orders/{$order->id}/close")->assertOk();
        $this->actingAs($this->manager)->postJson("/api/call-center/orders/{$order->id}/close")->assertOk();

        $events = IntegrationOutbox::where('event_type', TakeawayDispatcher::EVENT_CREATED)->get();
        $this->assertCount(1, $events);
        $this->assertSame($order->public_ref, $events[0]->aggregate_ref);
        $this->assertSame('أحمد', $events[0]->payload['order']['customer']['name']);
        $this->assertSame('paid', $events[0]->payload['order']['payment_status']);
        $this->assertSame('new', $events[0]->payload['order']['status']);
        $this->assertNotNull($order->fresh()->tw_released_at);
    }

    public function test_takeaway_webhook_retries_with_backoff_then_resend_delivers_it_signed(): void
    {
        [$client, , $secret] = ExternalApiClient::issue('Driver App', 'https://takeaway.test/hooks');
        $order = $this->order(['payment_status' => Order::PAYMENT_STATUS_PAID, 'kitchen_release_status' => Order::KITCHEN_RELEASE_STATUS_RELEASED]);
        $this->actingAs($this->manager)->postJson("/api/call-center/orders/{$order->id}/close")->assertOk();
        $event = IntegrationOutbox::where('event_type', TakeawayDispatcher::EVENT_CREATED)->firstOrFail();

        Http::fake(['takeaway.test/*' => Http::sequence()->push('down', 500)->push(['ok' => true], 200)]);
        $this->artisan('takeaway:dispatch')->assertExitCode(0);

        $delivery = ExternalWebhookDelivery::firstOrFail();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(1, $delivery->attempt_count);
        $this->assertSame('HTTP 500', $delivery->last_error);
        $this->assertTrue($delivery->next_attempt_at->isFuture(), 'backs off before the next try');
        $this->assertSame('failed', $this->closedOrderRow($order)['takeaway_sync']);

        $this->actingAs($this->manager)->postJson("/api/call-center/orders/{$order->id}/takeaway-resend")
            ->assertOk()->assertJsonPath('data.takeaway_sync', 'sent');

        Http::assertSent(function ($request) use ($event, $secret, $order) {
            return $request->hasHeader('Idempotency-Key', $event->outbox_ref)
                && $request->hasHeader('X-Event', 'order.created')
                && $request->header('X-Signature')[0] === TakeawayDispatcher::sign($secret, $request->header('X-Timestamp')[0], $request->body())
                && $request['data']['id'] === $order->id;
        });
        $this->assertSame(2, \DB::table('external_webhook_attempts')->count(), 'every attempt is logged');
        $this->assertSame('sent', $this->closedOrderRow($order)['takeaway_sync']);
    }

    public function test_takeaway_webhooks_are_a_noop_without_a_webhook_client(): void
    {
        $order = $this->order(['payment_status' => Order::PAYMENT_STATUS_PAID, 'kitchen_release_status' => Order::KITCHEN_RELEASE_STATUS_RELEASED]);
        $this->actingAs($this->manager)->postJson("/api/call-center/orders/{$order->id}/close")->assertOk();

        Http::fake();
        $this->artisan('takeaway:dispatch')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame('pending', $this->closedOrderRow($order)['takeaway_sync']);
    }

    private function closedOrderRow(Order $order): array
    {
        return collect($this->actingAs($this->manager)->getJson('/api/call-center/closed-orders')->assertOk()->json('data.data'))
            ->firstWhere('id', $order->id);
    }

    private function order(array $overrides = []): Order
    {
        return Order::create($overrides + [
            'order_number' => uniqid('ORD-CLOSE-'), 'branch_id' => $this->branch->id,
            'order_type' => 'takeaway', 'source' => 'call_center', 'status' => 'pending',
            'subtotal' => 100, 'total' => 100,
        ]);
    }
}
