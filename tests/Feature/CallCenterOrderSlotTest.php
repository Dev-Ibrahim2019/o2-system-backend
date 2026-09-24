<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderSlot;
use App\Models\User;
use App\Services\CallCenter\OrderSlotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CallCenterOrderSlotTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        config(['call-center.slots.reuse_cooldown_seconds' => 0]);
        $this->branch = Branch::factory()->create();
    }

    public function test_new_orders_take_the_smallest_free_slot_and_keep_it(): void
    {
        $a = $this->order();
        $b = $this->order();
        $c = $this->order();

        $this->assertSame([1, 2, 3], [$this->slotOf($a), $this->slotOf($b), $this->slotOf($c)]);

        $b->update(['status' => 'cancelled']);
        $this->assertNull($this->slotOf($b));
        $this->assertSame(1, $this->slotOf($a), 'other orders never move');
        $this->assertSame(3, $this->slotOf($c), 'other orders never move');

        $d = $this->order();
        $this->assertSame(2, $this->slotOf($d), 'smallest free slot is reused');
    }

    public function test_slot_is_kept_through_payment_and_execution_and_released_only_on_close(): void
    {
        $order = $this->order();
        $invoice = Invoice::create([
            'number' => uniqid('INV-SLOT-'), 'order_id' => $order->id, 'branch_id' => $this->branch->id,
            'status' => 'awaiting_approval', 'subtotal' => 100, 'discount' => 0, 'total' => 100, 'invoice_date' => now(),
        ]);
        $this->assertSame(1, $this->slotOf($order));

        $invoice->update(['status' => 'paid']);
        $order->update(['payment_status' => Order::PAYMENT_STATUS_PAID, 'status' => 'confirmed']);
        $this->assertSame(1, $this->slotOf($order), 'paid + executed but not closed yet: the operator still needs to see it in place');

        $order->update(['status' => 'closed']);
        $this->assertNull($this->slotOf($order));
        $this->assertSame('closed', OrderSlot::where('order_id', $order->id)->value('release_reason'));
    }

    public function test_slot_is_released_when_order_is_cancelled(): void
    {
        $order = $this->order();
        $order->update(['status' => 'cancelled']);

        $this->assertNull($this->slotOf($order));
        $this->assertSame('cancelled', OrderSlot::where('order_id', $order->id)->value('release_reason'));
    }

    public function test_recently_released_slot_is_not_reused_during_cooldown(): void
    {
        config(['call-center.slots.reuse_cooldown_seconds' => 60]);
        $a = $this->order();
        $a->update(['status' => 'cancelled']);

        $b = $this->order();

        $this->assertSame(2, $this->slotOf($b));
        $cooling = app(OrderSlotService::class)->coolingSlots($this->branch->id);
        $this->assertCount(1, $cooling);
        $this->assertSame(1, $cooling[0]['slot_number']);
        $this->assertSame('cancelled', $cooling[0]['reason']);
    }

    public function test_full_branch_queues_order_and_freed_slot_goes_to_oldest_queued(): void
    {
        app(OrderSlotService::class)->setCapacity($this->branch->id, 2);
        $a = $this->order();
        $b = $this->order();
        $queuedFirst = $this->order();
        $queuedSecond = $this->order();

        $this->assertNull($this->slotOf($queuedFirst));
        $this->assertNull($this->slotOf($queuedSecond));
        $this->assertSame(
            [$queuedFirst->id, $queuedSecond->id],
            app(OrderSlotService::class)->queuedOrders($this->branch->id)->pluck('id')->all(),
        );

        $a->update(['status' => 'cancelled']);

        $this->assertSame(1, $this->slotOf($queuedFirst));
        $this->assertNull($this->slotOf($queuedSecond));
        $this->assertSame(2, $this->slotOf($b));
    }

    public function test_shrinking_capacity_keeps_existing_orders_and_stops_issuing_higher_numbers(): void
    {
        $orders = [$this->order(), $this->order(), $this->order()];
        app(OrderSlotService::class)->setCapacity($this->branch->id, 2);

        $this->assertSame(3, $this->slotOf($orders[2]), 'slot above new capacity stays until freed');

        $orders[2]->update(['status' => 'cancelled']);
        $orders[0]->update(['status' => 'cancelled']);
        $again = $this->order();
        $noRoom = $this->order();

        $this->assertSame(1, $this->slotOf($again));
        $this->assertNull($this->slotOf($noRoom), 'slot 3 is above capacity, so it is never issued again');
    }

    public function test_non_call_center_orders_never_get_a_slot(): void
    {
        $order = $this->order(['source' => 'pos']);

        $this->assertNull($this->slotOf($order));
        $this->assertSame(0, OrderSlot::count());
    }

    public function test_reconcile_releases_orders_changed_behind_the_models_back(): void
    {
        $order = $this->order();
        Order::withoutGlobalScopes()->whereKey($order->id)->toBase()->update(['status' => 'cancelled']);
        $this->assertSame(1, $this->slotOf($order), 'query-builder update bypasses observers');

        $result = app(OrderSlotService::class)->reconcile();

        $this->assertSame(1, $result['released']);
        $this->assertNull($this->slotOf($order));
    }

    public function test_slots_endpoint_returns_board_with_orders_queue_and_capacity(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        Role::findOrCreate('call-center-manager', 'web');
        $user->assignRole('call-center-manager');
        $order = $this->order();

        $this->actingAs($user)->getJson('/api/call-center/slots')
            ->assertOk()
            ->assertJsonPath('data.capacity', 200)
            ->assertJsonPath('data.occupied', 1)
            ->assertJsonPath('data.slots.0.slot_number', 1)
            ->assertJsonPath('data.slots.0.order.id', $order->id);

        $this->actingAs($user)->putJson('/api/call-center/slots/capacity', ['branch_id' => $this->branch->id, 'capacity' => 50])
            ->assertOk()
            ->assertJsonPath('data.capacity', 50);
    }

    public function test_capacity_update_is_forbidden_for_plain_agents(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        Role::findOrCreate('call-center', 'web');
        $user->assignRole('call-center');

        $this->actingAs($user)
            ->putJson('/api/call-center/slots/capacity', ['branch_id' => $this->branch->id, 'capacity' => 50])
            ->assertForbidden();
    }

    public function test_an_order_created_from_a_clicked_empty_slot_takes_that_slot(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        Role::findOrCreate('call-center-manager', 'web');
        $user->assignRole('call-center-manager');
        $existing = $this->order();
        $item = \App\Models\Item::factory()->create();

        $create = fn (array $extra) => $this->actingAs($user)->postJson('/api/orders', $extra + [
            'branch_id' => $this->branch->id, 'order_type' => 'takeaway', 'source' => 'call_center',
            'customer_name' => 'عميل', 'customer_phone' => '0599000111',
            'items' => [['item_id' => $item->id, 'quantity' => 1, 'unit_price' => 10]],
        ]);

        $picked = $create(['slot_number' => 37])->assertCreated()->assertJsonPath('data.slot_number', 37);
        $this->assertSame(37, $this->slotOf(Order::find($picked->json('data.id'))));
        $this->assertSame(1, $this->slotOf($existing), 'existing orders never move');

        // الخانة 37 انحجزت: الطلب التالي اللي طلبها بياخد أصغر خانة فاضية بدلها
        $create(['slot_number' => 37])->assertCreated()->assertJsonPath('data.slot_number', 2);
        // بدون خانة محددة: السلوك القديم (أصغر خانة فاضية)
        $create([])->assertCreated()->assertJsonPath('data.slot_number', 3);
        // خانة فوق السعة ما بتنعطى
        $create(['slot_number' => 999])->assertCreated()->assertJsonPath('data.slot_number', 4);
    }

    private function order(array $overrides = []): Order
    {
        return Order::create($overrides + [
            'order_number' => uniqid('ORD-SLOT-'), 'branch_id' => $this->branch->id,
            'order_type' => 'takeaway', 'source' => 'call_center', 'status' => 'pending',
            'subtotal' => 100, 'total' => 100,
        ]);
    }

    private function slotOf(Order $order): ?int
    {
        return OrderSlot::where('order_id', $order->id)->whereNull('released_at')->value('slot_number');
    }
}
