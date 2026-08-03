<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceControllerCallCenterDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_invoice_auto_releases_order_when_production_tickets_are_missing(): void
    {
        [$user, $order] = $this->orderWithoutProductionTickets('pos');

        $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/invoice")
            ->assertCreated();

        $this->assertDatabaseHas('invoices', ['order_id' => $order->id, 'status' => 'draft']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'confirmed']);
        $this->assertDatabaseCount('production_tickets', 1);
        $this->assertTrue($order->items()->first()->is_printed_direct);
    }

    public function test_call_center_order_can_create_a_draft_invoice_without_production_tickets(): void
    {
        [$user, $order] = $this->orderWithoutProductionTickets('call_center');

        $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/invoice")
            ->assertCreated();

        $this->assertDatabaseHas('invoices', [
            'order_id' => $order->id,
            'status' => 'draft',
        ]);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('held', $order->fresh()->kitchen_release_status);
        $this->assertDatabaseCount('production_tickets', 0);
    }

    private function orderWithoutProductionTickets(string $source): array
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $item = Item::factory()->create();
        $order = Order::create([
            'order_number' => 'ORD-'.strtoupper($source).'-'.uniqid(),
            'branch_id' => $branch->id,
            'order_type' => 'takeaway',
            'source' => $source,
            'status' => 'pending',
            'subtotal' => 10,
            'total' => 10,
        ]);

        OrderItem::create([
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

        return [$user, $order];
    }
}
