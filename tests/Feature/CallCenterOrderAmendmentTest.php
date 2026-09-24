<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderActivityLog;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\ProductionTicket;
use App\Models\User;
use App\Services\CallCenter\OrderFlowService;
use App\Services\Invoice\InvoiceFromOrderService;
use App\Services\Printing\OrderPrintingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CallCenterOrderAmendmentTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $manager;
    private User $agent;
    private PaymentMethod $bank;

    protected function setUp(): void
    {
        parent::setUp();
        config(['call-center.print_on_execute' => true]);
        $this->mock(OrderPrintingService::class, fn ($mock) => $mock->shouldReceive('printTicket')->andReturn(['success' => true]));

        $this->branch = Branch::factory()->create();
        $this->manager = User::factory()->create(['branch_id' => $this->branch->id, 'name' => 'المدير']);
        Role::findOrCreate('call-center-manager', 'web');
        $this->manager->assignRole('call-center-manager');

        Permission::findOrCreate('call-center.create-order', 'web');
        Role::findOrCreate('call-center', 'web');
        $this->agent = User::factory()->create(['branch_id' => $this->branch->id, 'name' => 'الموظف']);
        $this->agent->assignRole('call-center');
        $this->agent->givePermissionTo('call-center.create-order');

        Account::firstOrCreate(['code' => '4'], ['name' => '4', 'type' => 'revenue', 'normal_balance' => 'credit', 'allow_posting' => false, 'is_active' => true]);
        $asset = Account::firstOrCreate(['code' => 'AMEND-BANK'], ['name' => 'bank', 'type' => 'asset', 'normal_balance' => 'debit', 'allow_posting' => true, 'is_active' => true]);
        $this->bank = PaymentMethod::create(['name' => 'bank', 'type' => 'bank', 'account_id' => $asset->id, 'is_active' => true, 'is_entity' => false]);
    }

    public function test_before_execution_items_can_be_edited_freely_and_the_unpaid_invoice_follows(): void
    {
        [$order, $shawarma, $juice] = $this->order([50 => 2, 20 => 1]);
        $extra = $this->item(30);

        $this->actingAs($this->agent)->putJson("/api/call-center/orders/{$order->id}", ['items' => [
            ['item_id' => $shawarma->id, 'quantity' => 1],
            ['item_id' => $extra->id, 'quantity' => 2],
        ]])->assertOk()->assertJsonPath('data.total', 110);

        $order = $order->fresh();
        $this->assertEquals(110, $order->total);
        $this->assertEquals(110, $order->invoice->total, 'unpaid invoice is rebuilt from the edited order');
        $this->assertSame(0, OrderItem::where('order_id', $order->id)->where('item_id', $juice->id)->count());
        $this->assertSame(0, ProductionTicket::where('order_id', $order->id)->count(), 'nothing was executed, so no kitchen tickets');
        $this->assertSame(1, OrderActivityLog::where('order_id', $order->id)->where('action_type', 'edited')->count());
    }

    public function test_after_execution_removal_needs_a_reason_and_issues_a_cancellation_ticket(): void
    {
        [$order, $shawarma, $juice] = $this->order([50 => 2, 20 => 1]);
        $this->payAndExecute($order);
        $juiceRow = OrderItem::where('order_id', $order->id)->where('item_id', $juice->id)->firstOrFail();

        $this->actingAs($this->manager)->deleteJson("/api/call-center/orders/{$order->id}/items/{$juiceRow->id}")
            ->assertStatus(422)->assertJsonPath('errors.reason.0', 'سبب التعديل بعد الدفع إلزامي.');

        $this->actingAs($this->manager)->deleteJson("/api/call-center/orders/{$order->id}/items/{$juiceRow->id}", ['reason' => 'العميل غيّر رأيه'])
            ->assertOk()
            ->assertJsonPath('data.change_tickets.0.success', true)
            ->assertJsonPath('data.warnings.0', 'الإجمالي الجديد (100.00) لا يطابق الفاتورة المدفوعة (120.00).');

        $juiceRow->refresh();
        $this->assertSame('cancelled', $juiceRow->status);
        $this->assertSame('العميل غيّر رأيه', $juiceRow->cancel_reason);
        $this->assertNull($juiceRow->ticketItem);
        $ticket = ProductionTicket::where('order_id', $order->id)->where('type', 'cancellation')->firstOrFail();
        $this->assertEquals([['name' => $juice->name_ar, 'quantity' => 1, 'action' => 'cancel', 'notes' => 'العميل غيّر رأيه']], $ticket->lines);
        $this->assertEquals(100, $order->fresh()->total);
        $this->assertSame('executed', OrderFlowService::describe($order->fresh())['execution_status']);
    }

    public function test_a_plain_agent_cannot_change_items_after_payment_but_a_supervisor_can(): void
    {
        [$order, , $juice] = $this->order([50 => 1, 20 => 1]);
        $this->payAndExecute($order);
        $row = OrderItem::where('order_id', $order->id)->where('item_id', $juice->id)->firstOrFail();

        $this->actingAs($this->agent)->deleteJson("/api/call-center/orders/{$order->id}/items/{$row->id}", ['reason' => 'سبب'])
            ->assertForbidden();
        $this->assertNotSame('cancelled', $row->fresh()->status);

        $this->actingAs($this->manager)->deleteJson("/api/call-center/orders/{$order->id}/items/{$row->id}", ['reason' => 'سبب'])
            ->assertOk();
    }

    public function test_items_added_after_execution_go_to_the_kitchen_in_a_new_ticket_with_only_the_new_items(): void
    {
        [$order, $shawarma] = $this->order([50 => 1]);
        $this->payAndExecute($order);
        $firstTicket = ProductionTicket::where('order_id', $order->id)->firstOrFail();
        $extra = $this->item(30);

        $this->actingAs($this->manager)->putJson("/api/call-center/orders/{$order->id}", [
            'items' => [['item_id' => $shawarma->id, 'quantity' => 1], ['item_id' => $extra->id, 'quantity' => 1]],
            'reason' => 'العميل أضاف صنف',
        ])->assertOk();

        $tickets = ProductionTicket::where('order_id', $order->id)->where('type', 'order')->with('ticketItems.orderItem')->get();
        $this->assertCount(2, $tickets);
        $newTicket = $tickets->firstWhere('id', '!=', $firstTicket->id);
        $this->assertSame([$extra->id], $newTicket->ticketItems->pluck('orderItem.item_id')->all());
        $this->assertSame(1, $firstTicket->fresh()->ticketItems()->count(), 'the original ticket is untouched');
    }

    public function test_edit_lock_blocks_a_second_editor_until_released_or_expired(): void
    {
        [$order, $shawarma] = $this->order([50 => 1]);

        $this->actingAs($this->agent)->postJson("/api/call-center/orders/{$order->id}/edit-lock")->assertOk();
        $this->actingAs($this->manager)->postJson("/api/call-center/orders/{$order->id}/edit-lock")
            ->assertStatus(409)->assertJsonFragment(['message' => 'الطلب قيد التعديل من الموظف.']);
        $this->actingAs($this->manager)->putJson("/api/call-center/orders/{$order->id}", ['items' => [['item_id' => $shawarma->id, 'quantity' => 3]]])
            ->assertStatus(409);

        $this->actingAs($this->agent)->postJson("/api/call-center/orders/{$order->id}/edit-lock")->assertOk(); // heartbeat
        $this->actingAs($this->agent)->deleteJson("/api/call-center/orders/{$order->id}/edit-lock")->assertOk();
        $this->actingAs($this->manager)->postJson("/api/call-center/orders/{$order->id}/edit-lock")->assertOk();

        $order->refresh()->update(['editing_until' => now()->subMinute()]);
        $this->actingAs($this->agent)->postJson("/api/call-center/orders/{$order->id}/edit-lock")->assertOk();
    }

    public function test_closed_orders_cannot_be_edited(): void
    {
        [$order, $shawarma] = $this->order([50 => 1]);
        $order->update(['status' => 'closed']);

        $this->actingAs($this->manager)->putJson("/api/call-center/orders/{$order->id}", ['items' => [['item_id' => $shawarma->id, 'quantity' => 2]]])
            ->assertStatus(422);
    }

    /** @return array{0: Order, 1: ?Item, 2: ?Item} */
    private function order(array $priceQuantity): array
    {
        $order = Order::create([
            'order_number' => 'ORD-'.now()->format('Ymd').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'branch_id' => $this->branch->id, 'call_center_agent_id' => $this->manager->id, 'order_type' => 'takeaway',
            'source' => 'call_center', 'status' => 'pending', 'subtotal' => 0, 'total' => 0,
        ]);
        $items = [];
        foreach ($priceQuantity as $price => $qty) {
            $item = $this->item($price);
            $items[] = $item;
            OrderItem::create([
                'order_id' => $order->id, 'item_id' => $item->id, 'department_id' => $item->department_id,
                'item_name' => $item->name, 'item_name_ar' => $item->name_ar, 'quantity' => $qty, 'price' => $price,
                'total' => $price * $qty, 'status' => 'pending',
            ]);
        }
        $order->recalculateTotals();
        app(InvoiceFromOrderService::class)->createFromOrder($order->fresh(), [], $this->manager->id);

        return [$order->fresh(), ...array_pad($items, 2, null)];
    }

    private function item(float $price): Item
    {
        $item = Item::factory()->create();
        $item->branches()->attach($this->branch->id, ['price' => $price, 'is_active' => true]);

        return $item;
    }

    private function payAndExecute(Order $order): void
    {
        $this->actingAs($this->manager)->postJson("/api/call-center/orders/{$order->id}/confirm-transfer", [
            'reference_number' => 'AMEND-'.$order->id,
            'payment_method_id' => $this->bank->id,
            'amount' => (float) $order->fresh()->total,
            'idempotency_key' => uniqid('idem-'),
        ])->assertOk()->assertJsonPath('data.kitchen_release_status', 'released');
    }
}
