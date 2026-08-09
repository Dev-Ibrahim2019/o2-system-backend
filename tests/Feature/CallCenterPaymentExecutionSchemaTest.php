<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentConfirmation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CallCenterPaymentExecutionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_have_nullable_call_center_payment_execution_fields(): void
    {
        $this->assertTrue(Schema::hasColumns('orders', [
            'payment_policy',
            'payment_status',
            'kitchen_release_status',
            'kitchen_released_at',
            'kitchen_released_by',
        ]));

        $columns = collect(Schema::getColumns('orders'))->keyBy('name');

        $this->assertTrue($columns['payment_policy']['nullable']);
        $this->assertTrue($columns['payment_status']['nullable']);
        $this->assertTrue($columns['kitchen_release_status']['nullable']);

        $indexedColumns = collect(Schema::getIndexes('orders'))
            ->pluck('columns')
            ->map(fn (array $columns) => array_values($columns));

        $this->assertTrue($indexedColumns->contains(['payment_policy']));
        $this->assertTrue($indexedColumns->contains(['payment_status']));
        $this->assertTrue($indexedColumns->contains(['kitchen_release_status']));

        $releaseForeign = collect(Schema::getForeignKeys('orders'))
            ->first(fn (array $key) => in_array('kitchen_released_by', $key['columns'], true));

        $this->assertNotNull($releaseForeign);
        $this->assertSame('users', $releaseForeign['foreign_table']);
    }

    public function test_approved_payment_policies_include_mixed_execution(): void
    {
        $this->assertSame('manual_confirmation', Order::PAYMENT_POLICY_MANUAL_CONFIRMATION);
        $this->assertSame('instant_debit', Order::PAYMENT_POLICY_INSTANT_DEBIT);
        $this->assertSame('mixed', Order::PAYMENT_POLICY_MIXED);
        $this->assertSame([
            'manual_confirmation',
            'instant_debit',
            'mixed',
        ], Order::PAYMENT_POLICIES);

        $this->assertSame([
            'unpaid',
            'awaiting_confirmation',
            'processing',
            'paid',
            'failed',
            'refunded',
        ], Order::PAYMENT_STATUSES);
        $this->assertSame([
            'held',
            'releasing',
            'released',
            'release_failed',
        ], Order::KITCHEN_RELEASE_STATUSES);
        $this->assertNotContains('pending_collection', Order::PAYMENT_STATUSES);
    }

    public function test_payment_confirmations_have_idempotency_and_reference_unique_indexes(): void
    {
        $this->assertTrue(Schema::hasColumns('payment_confirmations', [
            'order_id',
            'payment_method_id',
            'reference_number',
            'normalized_reference_number',
            'amount',
            'status',
            'idempotency_key',
            'confirmed_by',
            'confirmed_at',
        ]));

        $columns = collect(Schema::getColumns('payment_confirmations'))->keyBy('name');
        $this->assertFalse($columns['payment_method_id']['nullable']);

        $uniqueIndexes = collect(Schema::getIndexes('payment_confirmations'))
            ->where('unique', true)
            ->pluck('columns')
            ->map(fn (array $columns) => array_values($columns));

        $this->assertTrue($uniqueIndexes->contains([
            'payment_method_id',
            'normalized_reference_number',
        ]));
        $this->assertFalse($uniqueIndexes->contains(['reference_number']));
        $this->assertTrue($uniqueIndexes->contains(['idempotency_key']));
    }

    public function test_payment_confirmation_is_reserved_for_manual_confirmation(): void
    {
        $this->assertSame(
            Order::PAYMENT_POLICY_MANUAL_CONFIRMATION,
            PaymentConfirmation::PAYMENT_POLICY
        );
    }

    public function test_each_order_item_can_have_only_one_production_ticket_item(): void
    {
        $uniqueIndexes = collect(Schema::getIndexes('production_ticket_items'))
            ->where('unique', true)
            ->pluck('columns')
            ->map(fn (array $columns) => array_values($columns));

        $this->assertTrue($uniqueIndexes->contains(['order_item_id']));
    }
}
