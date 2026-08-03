<?php

namespace Tests\Feature;

use App\Models\{Branch, CallTicket, Customer, Item, User};
use App\Http\Controllers\Api\OrderController;
use App\Models\{Order, OrderItem, ProductionTicket, ProductionTicketItem};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CallCenterOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_caller_customer_address_order_and_ticket_are_created_atomically(): void
    {
        [$user, $branch] = $this->operator();
        $item = Item::factory()->create();
        $item->branches()->attach($branch->id, ['price' => 12.5, 'is_active' => true]);
        $ticket = CallTicket::create([
            'external_call_id' => 'atomic-new-1', 'branch_id' => $branch->id, 'agent_id' => $user->id,
            'direction' => 'inbound', 'status' => 'in_progress', 'incoming_phone' => '0599001122',
            'normalized_phone' => '+970599001122', 'source' => 'manual', 'started_at' => now(),
        ]);

        $response = $this->actingAs($user)->postJson('/api/call-center/orders', [
            'call_ticket_id' => $ticket->id,
            'external_call_id' => 'atomic-new-1',
            'branch_id' => $branch->id,
            'order_type' => 'delivery',
            'customer' => ['name' => 'عميل اختبار الكول سنتر', 'phone' => '+970599001122'],
            'address' => ['city' => 'غزة', 'area' => 'الرمال', 'street' => 'شارع الاختبار'],
            'items' => [['item_id' => $item->id, 'quantity' => 2]],
        ])->assertCreated();

        $customerId = $response->json('data.customer.id');
        $orderId = $response->json('data.order.id');
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'customer_id' => $customerId, 'customer_name' => 'عميل اختبار الكول سنتر']);
        $this->assertDatabaseHas('customer_addresses', ['customer_id' => $customerId, 'area' => 'الرمال']);
        $this->assertDatabaseHas('call_tickets', ['id' => $ticket->id, 'customer_id' => $customerId, 'linked_order_id' => $orderId]);
    }

    public function test_existing_customer_is_reused_without_duplicate(): void
    {
        [$user, $branch] = $this->operator();
        $customer = Customer::create(['name' => 'Existing', 'code' => 'EX-1', 'phone' => '599001122', 'branch_id' => $branch->id]);
        $item = Item::factory()->create();
        $item->branches()->attach($branch->id, ['price' => 10, 'is_active' => true]);
        $ticket = CallTicket::create([
            'external_call_id' => 'reuse-1', 'branch_id' => $branch->id, 'agent_id' => $user->id,
            'direction' => 'inbound', 'status' => 'in_progress', 'incoming_phone' => '+970599001122',
            'normalized_phone' => '+970599001122', 'source' => 'manual', 'started_at' => now(),
        ]);

        $this->actingAs($user)->postJson('/api/call-center/orders', [
            'call_ticket_id' => $ticket->id, 'branch_id' => $branch->id, 'order_type' => 'takeaway',
            'customer' => ['name' => 'Should not duplicate', 'phone' => '+970599001122'],
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.customer.id', $customer->id);

        $this->assertDatabaseCount('customers', 1);
    }

    public function test_manual_call_center_invoice_can_be_saved_without_a_call_ticket(): void
    {
        [$user, $branch] = $this->operator();
        $item = Item::factory()->create();
        $item->branches()->attach($branch->id, ['price' => 9.5, 'is_active' => true]);

        $response = $this->actingAs($user)->postJson('/api/call-center/orders', [
            'branch_id' => $branch->id,
            'order_type' => 'takeaway',
            'customer' => [
                'name' => 'عميل فاتورة يدوية',
                'phone' => '+970599001133',
            ],
            'items' => [['item_id' => $item->id, 'quantity' => 2]],
        ])->assertCreated();

        $response->assertJsonPath('data.call_ticket', null);
        $this->assertDatabaseHas('orders', [
            'id' => $response->json('data.order.id'),
            'source' => 'call_center',
            'call_center_agent_id' => $user->id,
        ]);
        $this->assertDatabaseCount('call_tickets', 0);
    }

    public function test_unpaid_call_center_order_cannot_be_sent_to_kitchen(): void
    {
        [$user, $branch] = $this->operator();
        $order = Order::create([
            'order_number' => 'ORD-CC-UNPAID',
            'branch_id' => $branch->id,
            'order_type' => 'takeaway',
            'source' => 'call_center',
            'status' => 'pending',
            'call_center_agent_id' => $user->id,
        ]);

        $response = app(OrderController::class)->confirm($order);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);
        $this->assertDatabaseCount('production_tickets', 0);
    }

    public function test_paid_call_center_order_is_sent_once_and_retry_creates_no_duplicate_ticket(): void
    {
        [$user, $branch] = $this->operator();
        $item = Item::factory()->create();
        $order = Order::create([
            'order_number' => 'ORD-CC-PAID',
            'branch_id' => $branch->id,
            'order_type' => 'takeaway',
            'source' => 'call_center',
            'status' => 'pending',
            'payment_status' => 'paid',
            'call_center_agent_id' => $user->id,
            'subtotal' => 10,
            'total' => 10,
        ]);
        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'item_id' => $item->id,
            'department_id' => $item->department_id,
            'item_name' => $item->name,
            'item_name_ar' => $item->name_ar,
            'quantity' => 1,
            'price' => 10,
            'total' => 10,
            'status' => 'pending',
        ]);

        $this->assertSame(200, app(OrderController::class)->confirm($order)->getStatusCode());
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertNotNull($orderItem->fresh()->sent_to_kitchen_at);
        $this->assertSame(1, ProductionTicket::where('order_id', $order->id)->count());
        $this->assertSame(1, ProductionTicketItem::where('order_item_id', $orderItem->id)->count());

        $this->assertSame(200, app(OrderController::class)->confirm($order->fresh())->getStatusCode());
        $this->assertSame(1, ProductionTicket::where('order_id', $order->id)->count());
        $this->assertSame(1, ProductionTicketItem::where('order_item_id', $orderItem->id)->count());
    }

    private function operator(): array
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        Permission::findOrCreate('access-call-center-interface', 'web');
        $user->givePermissionTo('access-call-center-interface');
        return [$user, $branch];
    }
}
