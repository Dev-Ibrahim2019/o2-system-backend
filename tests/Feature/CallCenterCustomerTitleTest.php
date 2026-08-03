<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CallCenterCustomerTitleTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_save_read_update_and_remove_title_without_changing_classification(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        Permission::findOrCreate('access-call-center-interface', 'web');
        $user->givePermissionTo('access-call-center-interface');
        $customer = Customer::query()->create([
            'name' => 'عميل اختبار',
            'code' => 'TITLE-TEST-1',
            'category' => 'vip',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/call-center/customers/{$customer->id}/title", ['title' => 'الدكتور'])
            ->assertOk()
            ->assertJsonPath('data.title', 'الدكتور');

        $this->actingAs($user)
            ->getJson("/api/call-center/customers/{$customer->id}/full-profile")
            ->assertOk()
            ->assertJsonPath('data.profile.customer.title', 'الدكتور')
            ->assertJsonPath('data.profile.customer.category', 'vip');

        $this->actingAs($user)
            ->patchJson("/api/call-center/customers/{$customer->id}/title", ['title' => 'المهندس'])
            ->assertOk()
            ->assertJsonPath('data.title', 'المهندس');

        $this->actingAs($user)
            ->patchJson("/api/call-center/customers/{$customer->id}/title", ['title' => null])
            ->assertOk()
            ->assertJsonPath('data.title', null);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'title' => null,
            'category' => 'vip',
        ]);
    }

    public function test_title_is_limited_to_database_length(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        Permission::findOrCreate('access-call-center-interface', 'web');
        $user->givePermissionTo('access-call-center-interface');
        $customer = Customer::query()->create(['name' => 'عميل', 'code' => 'TITLE-TEST-2']);

        $this->actingAs($user)
            ->patchJson("/api/call-center/customers/{$customer->id}/title", ['title' => str_repeat('a', 33)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');
    }
}
