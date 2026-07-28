<?php

namespace Tests\Feature;

use App\Models\{Branch, Customer, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CrmCustomerDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private function user(Branch $branch): User
    {
        Permission::findOrCreate('access-call-center','web');
        $user=User::factory()->create(['branch_id'=>$branch->id]); $user->givePermissionTo('access-call-center');
        return $user;
    }

    public function test_directory_filters_category_loyalty_and_branch(): void
    {
        $branch=Branch::factory()->create(); $other=Branch::factory()->create(); $user=$this->user($branch);
        Customer::create(['code'=>'CRM-1','name'=>'VIP Match','phone'=>'599000001','category'=>'vip','status'=>'active','branch_id'=>$branch->id])->forceFill(['loyalty_points'=>500])->save();
        Customer::create(['code'=>'CRM-2','name'=>'Wrong Branch','phone'=>'599000002','category'=>'vip','status'=>'active','branch_id'=>$other->id])->forceFill(['loyalty_points'=>500])->save();
        $response=$this->actingAs($user)->getJson('/api/call-center/customers/directory?category=vip&loyalty_min=400');
        $response->assertOk()->assertJsonCount(1,'data.data')->assertJsonPath('data.data.0.name','VIP Match');
    }

    public function test_create_normalizes_palestinian_phone_and_rejects_duplicate_variant(): void
    {
        $branch=Branch::factory()->create(); $user=$this->user($branch);
        $this->actingAs($user)->postJson('/api/call-center/customers',['name'=>'Normalized','phone'=>'+970 59 900 0003','branch_id'=>$branch->id])->assertCreated();
        $this->assertDatabaseHas('customers',['phone'=>'599000003']);
        $this->actingAs($user)->postJson('/api/call-center/customers',['name'=>'Duplicate','phone'=>'0599000003','branch_id'=>$branch->id])->assertUnprocessable()->assertJsonValidationErrors('phone');
    }
}
