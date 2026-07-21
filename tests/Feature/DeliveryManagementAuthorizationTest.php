<?php

namespace Tests\Feature;

use App\Models\{Branch, DeliveryTrip, DeliveryTripStop, Employee, Order, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DeliveryManagementAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_routes_require_manage_orders_permission(): void
    {
        $user=User::factory()->create();
        $this->actingAs($user)->getJson('/api/delivery-management/trips')->assertForbidden();
    }

    public function test_user_cannot_read_trip_from_another_branch(): void
    {
        Permission::findOrCreate('manage-orders','web');
        $own=Branch::factory()->create(); $other=Branch::factory()->create();
        $user=User::factory()->create(['branch_id'=>$own->id]); $user->givePermissionTo('manage-orders');
        $trip=DeliveryTrip::create(['branch_id'=>$other->id,'number'=>'TRIP-X','status'=>'draft','created_by'=>$user->id]);
        $this->actingAs($user)->getJson("/api/delivery-management/trips/{$trip->id}")->assertForbidden();
    }

    public function test_failed_stop_recovers_order_and_releases_driver(): void
    {
        Permission::findOrCreate('manage-orders','web');
        $branch=Branch::factory()->create(); $user=User::factory()->create(['branch_id'=>$branch->id]); $user->givePermissionTo('manage-orders');
        $driver=Employee::factory()->create(['branch_id'=>$branch->id,'operational_role'=>'delivery_driver','is_operations_enabled'=>true,'status'=>'ACTIVE']);
        $order=Order::create(['branch_id'=>$branch->id,'order_type'=>'delivery','status'=>Order::STATUS_OUT_FOR_DELIVERY,'driver_id'=>$driver->id,'delivery_started_at'=>now()]);
        $trip=DeliveryTrip::create(['branch_id'=>$branch->id,'driver_id'=>$driver->id,'number'=>'TRIP-Y','status'=>'in_progress','started_at'=>now(),'created_by'=>$user->id]);
        $stop=DeliveryTripStop::create(['delivery_trip_id'=>$trip->id,'order_id'=>$order->id,'sequence'=>1,'status'=>'pending']);
        $this->actingAs($user)->patchJson("/api/delivery-management/trips/{$trip->id}/stops/{$stop->id}",['status'=>'failed','failure_reason'=>'تعذر الوصول'])->assertOk();
        $this->assertDatabaseHas('orders',['id'=>$order->id,'status'=>Order::STATUS_PREPARATION,'driver_id'=>null]);
        $this->assertDatabaseHas('order_execution_events',['order_id'=>$order->id,'event_type'=>'delivery_recovered']);
    }
}
