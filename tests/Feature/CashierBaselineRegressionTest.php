<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\OrderController;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\DiningZone;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CashierBaselineRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_store_defaults_source_and_persists_item_takeaway_flag(): void
    {
        [$user, $branch, $item] = $this->operatorAndItem();

        $response = $this->actingAs($user)->postJson('/api/orders', [
            'branch_id' => $branch->id,
            'order_type' => 'takeaway',
            'items' => [[
                'item_id' => $item->id,
                'quantity' => 1,
                'unit_price' => 10,
                'is_takeaway' => true,
            ]],
        ])->assertCreated();

        $order = Order::findOrFail($response->json('data.id'));
        $this->assertSame('pos', $order->source);
        $this->assertTrue($order->items()->first()->is_takeaway);
    }

    public function test_update_changes_printed_item_takeaway_without_deleting_or_duplicating_it(): void
    {
        Log::spy();
        [$user, $branch, $item] = $this->operatorAndItem();
        $order = $this->orderWithItem($branch, $item);
        $orderItem = $order->items()->first();
        $orderItem->update(['is_printed_direct' => true, 'is_takeaway' => true]);

        $this->actingAs($user)->putJson("/api/orders/{$order->id}", [
            'skip_sync' => true,
            'items' => [[
                'item_id' => $item->id,
                'quantity' => 1,
                'unit_price' => 10,
                'is_takeaway' => false,
            ]],
        ])->assertOk();

        $this->assertDatabaseCount('order_items', 1);
        $this->assertTrue($orderItem->fresh()->is_printed_direct);
        $this->assertFalse($orderItem->fresh()->is_takeaway);
    }

    public function test_pos_confirmation_marks_items_printed_and_never_duplicates_ticket_items(): void
    {
        [, $branch, $item] = $this->operatorAndItem();
        $order = $this->orderWithItem($branch, $item);

        $this->assertSame(200, app(OrderController::class)->confirm($order)->getStatusCode());
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertTrue($order->items()->first()->is_printed_direct);
        $this->assertDatabaseCount('production_ticket_items', 1);

        $this->assertSame(422, app(OrderController::class)->confirm($order->fresh())->getStatusCode());
        $this->assertDatabaseCount('production_ticket_items', 1);
    }

    public function test_table_transfer_preserves_cashier_baseline_state_changes(): void
    {
        Log::spy();
        [, $branch, $item] = $this->operatorAndItem();
        $zone = DiningZone::create(['branch_id' => $branch->id, 'name' => 'Main', 'code' => uniqid('Z')]);
        $old = $this->table($zone->id, $branch->id, 'T1', 'OCCUPIED');
        $new = $this->table($zone->id, $branch->id, 'T2', 'AVAILABLE');
        $order = $this->orderWithItem($branch, $item, [
            'dining_table_id' => $old->id,
            'table_number' => 'T1',
            'customer_count' => 3,
        ]);
        $old->update(['current_order_id' => $order->id, 'customer_count' => 3]);

        $response = app(OrderController::class)->transfer(
            Request::create('/orders/'.$order->id.'/transfer', 'POST', ['table_number' => 'T2']),
            $order
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($new->id, $order->fresh()->dining_table_id);
        $this->assertSame('AVAILABLE', $old->fresh()->status);
        $this->assertSame('OCCUPIED', $new->fresh()->status);
        $this->assertSame($order->id, $new->fresh()->current_order_id);
    }

    private function operatorAndItem(): array
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $item = Item::factory()->create();
        $item->branches()->attach($branch->id, ['price' => 10, 'is_active' => true]);
        return [$user, $branch, $item];
    }

    private function orderWithItem(Branch $branch, Item $item, array $extra = []): Order
    {
        $order = Order::create(array_merge([
            'order_number' => uniqid('ORD-POS-'), 'branch_id' => $branch->id,
            'order_type' => 'takeaway', 'source' => 'pos', 'status' => 'pending',
        ], $extra));
        OrderItem::create([
            'order_id' => $order->id, 'item_id' => $item->id, 'department_id' => $item->department_id,
            'item_name' => $item->name, 'quantity' => 1, 'price' => 10, 'total' => 10,
            'status' => 'pending', 'is_printed_direct' => false, 'is_takeaway' => false,
        ]);
        return $order;
    }

    private function table(int $zoneId, int $branchId, string $number, string $status): DiningTable
    {
        return DiningTable::create([
            'dining_zone_id' => $zoneId, 'branch_id' => $branchId,
            'code' => $number, 'table_number' => $number,
            'qr_code' => uniqid('QR-'), 'qr_url' => 'https://example.test/'.$number,
            'capacity' => 4, 'status' => $status,
        ]);
    }
}
