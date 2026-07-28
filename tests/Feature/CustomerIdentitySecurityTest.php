<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CustomerIdentitySecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_internal_customer_api(): void
    {
        $this->getJson('/api/customers')->assertUnauthorized();
    }

    public function test_authenticated_user_without_permission_is_forbidden(): void
    {
        $this->actingAs($this->user())->getJson('/api/customers')->assertForbidden();
    }

    public function test_authorized_user_can_list_customers(): void
    {
        $user = $this->user();
        $this->grant($user, 'crm.view-customers');

        $this->actingAs($user)->getJson('/api/customers')->assertOk();
    }

    public function test_phone_variants_cannot_create_duplicate_customers(): void
    {
        $user = $this->user();
        $this->grant($user, 'crm.create-customers');

        $this->actingAs($user)->postJson('/api/customers', [
            'name' => 'First',
            'phone' => '+970 59 123 4567',
        ])->assertCreated();

        $this->actingAs($user)->postJson('/api/customers', [
            'name' => 'Duplicate',
            'phone' => '0591234567',
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');

        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseHas('customer_phones', ['normalized_phone' => '+970591234567']);
    }

    public function test_soft_deleted_customer_is_rejected_when_creating_an_order(): void
    {
        $customer = Customer::create([
            'code' => 'DELETED-1',
            'name' => 'Deleted',
            'phone' => '599999999',
        ]);
        $customer->delete();

        $this->actingAs($this->user())->postJson('/api/orders', [
            'order_type' => 'takeaway',
            'customer_id' => $customer->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
    }

    private function user(): User
    {
        $branch = Branch::factory()->create();

        return User::factory()->create(['branch_id' => $branch->id]);
    }

    private function grant(User $user, string $permission): void
    {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }
}
