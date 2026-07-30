<?php

namespace Tests\Feature;

use App\Models\{Branch, Customer, Order, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OrderFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_call_center_agent_can_create_update_and_read_persisted_order_feedback(): void
    {
        [$user, $customer, $order] = $this->fixture('delivery');
        $url = "/api/call-center/customers/{$customer->id}/orders/{$order->id}/feedback";

        $this->actingAs($user)->putJson($url, [
            'food_quality' => 5,
            'service_quality' => 4,
            'delivery_speed' => 3,
            'notes' => 'وصل الطلب بحالة جيدة',
        ])->assertCreated()
            ->assertJsonPath('data.recorded_by', $user->id);

        $this->assertDatabaseHas('order_feedback', [
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'food_quality' => 5,
            'recorded_by' => $user->id,
        ]);

        $this->actingAs($user)->putJson($url, [
            'food_quality' => 4,
            'service_quality' => 5,
            'delivery_speed' => 4,
        ])->assertOk()->assertJsonPath('data.food_quality', 4);

        $this->assertDatabaseCount('order_feedback', 1);
        $this->actingAs($user)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.delivery_speed', 4);
    }

    public function test_delivery_speed_is_required_for_delivery_and_scores_are_between_one_and_five(): void
    {
        [$user, $customer, $order] = $this->fixture('delivery');

        $this->actingAs($user)->putJson(
            "/api/call-center/customers/{$customer->id}/orders/{$order->id}/feedback",
            ['food_quality' => 6, 'service_quality' => 0],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['food_quality', 'service_quality', 'delivery_speed']);
    }

    public function test_takeaway_ignores_delivery_speed_and_customer_order_mismatch_is_hidden(): void
    {
        [$user, $customer, $order] = $this->fixture('takeaway');
        $other = Customer::create([
            'name' => 'Other',
            'code' => 'OTHER-'.uniqid(),
            'phone' => '0599000002',
            'branch_id' => $order->branch_id,
        ]);

        $this->actingAs($user)->putJson(
            "/api/call-center/customers/{$customer->id}/orders/{$order->id}/feedback",
            ['food_quality' => 5, 'service_quality' => 5, 'delivery_speed' => 1],
        )->assertCreated()->assertJsonPath('data.delivery_speed', null);

        $this->actingAs($user)->getJson(
            "/api/call-center/customers/{$other->id}/orders/{$order->id}/feedback",
        )->assertNotFound();
    }

    private function fixture(string $orderType): array
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        Permission::findOrCreate('access-call-center-interface', 'web');
        $user->givePermissionTo('access-call-center-interface');
        $customer = Customer::create([
            'name' => 'Feedback Customer',
            'code' => 'FB-'.uniqid(),
            'phone' => '0599000001',
            'branch_id' => $branch->id,
        ]);
        $order = Order::create([
            'order_number' => 'ORD-FB-'.uniqid(),
            'branch_id' => $branch->id,
            'order_type' => $orderType,
            'source' => 'call_center',
            'status' => 'paid',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
        ]);

        return [$user, $customer, $order];
    }
}
