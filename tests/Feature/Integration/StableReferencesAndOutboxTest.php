<?php

namespace Tests\Feature\Integration;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\IntegrationOutbox;
use App\Models\Item;
use App\Models\Order;
use App\Models\User;
use App\Services\CallCenter\CallCenterOrderCreationService;
use App\Services\Integration\IntegrationOutboxWriter;
use App\Support\Integration\IntegrationReference;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StableReferencesAndOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_order_and_customer_receive_stable_opaque_references(): void
    {
        $branch = Branch::factory()->create();
        $customer = Customer::create([
            'name' => 'Reference Test Customer',
            'code' => 'REF-CUSTOMER-1',
            'phone' => '+970599123456',
            'branch_id' => $branch->id,
        ]);
        $order = Order::create([
            'order_number' => 'DISPLAY-ORDER-1',
            'branch_id' => $branch->id,
            'order_type' => 'takeaway',
            'source' => 'pos',
            'status' => 'pending',
        ]);

        $this->assertTrue(IntegrationReference::isValid($order->public_ref, IntegrationReference::ORDER_PREFIX));
        $this->assertTrue(IntegrationReference::isValid($customer->external_ref, IntegrationReference::CUSTOMER_PREFIX));
        $this->assertNotSame((string) $order->id, $order->public_ref);
        $this->assertStringNotContainsString($order->order_number, $order->public_ref);
        $this->assertStringNotContainsString('599123456', $customer->external_ref);

        $orderRef = $order->public_ref;
        $customerRef = $customer->external_ref;
        $order->update(['note' => 'ordinary update']);
        $customer->update(['name' => 'Updated Customer']);

        $this->assertSame($orderRef, $order->fresh()->public_ref);
        $this->assertSame($customerRef, $customer->fresh()->external_ref);
        $this->assertSame('DISPLAY-ORDER-1', $order->fresh()->order_number);
    }

    public function test_trusted_supplied_references_are_preserved_and_cannot_be_replaced(): void
    {
        $branch = Branch::factory()->create();
        $trustedOrderRef = IntegrationReference::order();
        $trustedCustomerRef = IntegrationReference::customer();

        $order = new Order([
            'order_number' => 'DISPLAY-ORDER-2',
            'branch_id' => $branch->id,
            'order_type' => 'takeaway',
            'source' => 'website',
            'status' => 'pending',
        ]);
        $order->forceFill(['public_ref' => $trustedOrderRef])->save();

        $customer = new Customer(['name' => 'Trusted Customer', 'code' => 'REF-CUSTOMER-2', 'branch_id' => $branch->id]);
        $customer->forceFill(['external_ref' => $trustedCustomerRef])->save();

        $this->assertSame($trustedOrderRef, $order->public_ref);
        $this->assertSame($trustedCustomerRef, $customer->external_ref);

        $this->expectException(LogicException::class);
        $order->forceFill(['public_ref' => IntegrationReference::order()])->save();
    }

    public function test_database_rejects_duplicate_order_and_customer_references(): void
    {
        $branch = Branch::factory()->create();
        $orderRef = IntegrationReference::order();
        $customerRef = IntegrationReference::customer();

        DB::table('orders')->insert($this->rawOrder($branch->id, 'DUP-ORDER-1', $orderRef));
        DB::table('customers')->insert([
            'name' => 'First', 'code' => 'DUP-CUSTOMER-1', 'external_ref' => $customerRef, 'branch_id' => $branch->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            DB::table('orders')->insert($this->rawOrder($branch->id, 'DUP-ORDER-2', $orderRef));
            $this->fail('Duplicate Order public_ref was accepted.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->expectException(QueryException::class);
        DB::table('customers')->insert([
            'name' => 'Second', 'code' => 'DUP-CUSTOMER-2', 'external_ref' => $customerRef, 'branch_id' => $branch->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_customer_external_reference_cannot_be_replaced(): void
    {
        $customer = Customer::create([
            'name' => 'Immutable Customer',
            'code' => 'IMMUTABLE-CUSTOMER',
        ]);

        $this->expectException(LogicException::class);
        $customer->forceFill(['external_ref' => IntegrationReference::customer()])->save();
    }

    public function test_writer_persists_typed_event_payload_and_schema_version(): void
    {
        $orderRef = IntegrationReference::order();
        $outbox = app(IntegrationOutboxWriter::class)->record(
            'order.created',
            'order',
            $orderRef,
            ['public_order_ref' => $orderRef, 'source' => 'test'],
            2,
        );

        $this->assertTrue(IntegrationReference::isValid($outbox->outbox_ref, IntegrationReference::OUTBOX_PREFIX));
        $this->assertSame($orderRef, $outbox->aggregate_ref);
        $this->assertSame(['public_order_ref' => $orderRef, 'source' => 'test'], $outbox->fresh()->payload);
        $this->assertSame(2, $outbox->schema_version);
        $this->assertNull($outbox->published_at);
        $this->assertSame(0, $outbox->attempt_count);

        $this->expectException(QueryException::class);
        IntegrationOutbox::create(array_merge(
            $outbox->only(['outbox_ref', 'event_type', 'aggregate_type', 'aggregate_ref', 'payload', 'schema_version', 'occurred_at', 'available_at']),
            ['attempt_count' => 0],
        ));
    }

    public function test_call_center_order_and_outbox_commit_atomically(): void
    {
        [$agent, $branch, $item] = $this->callCenterFixture();

        $result = app(CallCenterOrderCreationService::class)->create($this->orderData($branch, $item), $agent);
        $order = $result['order'];

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'public_ref' => $order->public_ref,
        ]);
        $this->assertDatabaseHas('integration_outbox', [
            'event_type' => 'order.created',
            'aggregate_type' => 'order',
            'aggregate_ref' => $order->public_ref,
            'schema_version' => 1,
        ]);
    }

    public function test_outer_rollback_removes_call_center_order_customer_and_outbox(): void
    {
        [$agent, $branch, $item] = $this->callCenterFixture();

        try {
            DB::transaction(function () use ($agent, $branch, $item): void {
                app(CallCenterOrderCreationService::class)->create($this->orderData($branch, $item), $agent);
                throw new RuntimeException('Force rollback after the real service wrote the Outbox.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Force rollback after the real service wrote the Outbox.', $exception->getMessage());
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('integration_outbox', 0);
    }

    private function callCenterFixture(): array
    {
        $branch = Branch::factory()->create();
        $agent = User::factory()->create(['branch_id' => $branch->id]);
        Permission::findOrCreate('access-call-center-interface', 'web');
        $agent->givePermissionTo('access-call-center-interface');
        $item = Item::factory()->create();
        $item->branches()->attach($branch->id, ['price' => 12.5, 'is_active' => true]);

        return [$agent, $branch, $item];
    }

    private function orderData(Branch $branch, Item $item): array
    {
        return [
            'branch_id' => $branch->id,
            'order_type' => 'takeaway',
            'customer' => ['name' => 'Atomic Customer', 'phone' => '+970599765432'],
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ];
    }

    private function rawOrder(int $branchId, string $number, string $publicRef): array
    {
        return [
            'public_ref' => $publicRef,
            'order_number' => $number,
            'branch_id' => $branchId,
            'order_type' => 'takeaway',
            'source' => 'pos',
            'status' => 'pending',
            'subtotal' => 0,
            'discount_value' => 0,
            'discount_amount' => 0,
            'total' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
