<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CrmAdminTest extends TestCase
{
    use RefreshDatabase;

    private function user(Branch $branch, array $permissions): User
    {
        $user = User::factory()->create(['branch_id' => $branch->id]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function customer(Branch $branch, string $name = 'عميل CRM'): Customer
    {
        return Customer::create([
            'name' => $name,
            'code' => 'CRM-'.fake()->unique()->numerify('####'),
            'phone' => '599'.fake()->unique()->numerify('######'),
            'status' => 'active',
            'branch_id' => $branch->id,
        ]);
    }

    public function test_guest_and_user_without_access_cannot_use_crm(): void
    {
        $this->getJson('/api/crm/dashboard')->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/crm/dashboard')->assertForbidden();
    }

    public function test_authorized_user_can_open_dashboard_and_directory(): void
    {
        $branch = Branch::factory()->create();
        $user = $this->user($branch, ['crm.access', 'crm.dashboard.view', 'crm.view-customers']);
        $this->customer($branch);

        $this->actingAs($user)->getJson('/api/crm/dashboard')
            ->assertOk()->assertJsonPath('data.customers.total', 1)
            ->assertJsonPath('data.financial', null);
        $this->actingAs($user)->getJson('/api/crm/customers?search=CRM')
            ->assertOk()->assertJsonCount(1, 'data.data');
    }

    public function test_branch_user_cannot_open_another_branch_customer(): void
    {
        $branch = Branch::factory()->create();
        $other = Branch::factory()->create();
        $user = $this->user($branch, ['crm.access', 'crm.view-customers']);
        $customer = $this->customer($other);

        $this->actingAs($user)->getJson("/api/crm/customers/{$customer->id}")->assertForbidden();
    }

    public function test_sensitive_notes_and_financial_data_are_protected(): void
    {
        $branch = Branch::factory()->create();
        $customer = $this->customer($branch);
        CustomerNote::create(['customer_id' => $customer->id, 'content' => 'عام', 'type' => 'general']);
        CustomerNote::create(['customer_id' => $customer->id, 'content' => 'سري', 'type' => 'sensitive']);
        $user = $this->user($branch, ['crm.access', 'crm.view-customers', 'crm.notes.view']);

        $this->actingAs($user)->getJson("/api/crm/customers/{$customer->id}/notes")
            ->assertOk()->assertJsonCount(1, 'data.data')
            ->assertJsonMissing(['content' => 'سري']);
        $this->actingAs($user)->getJson("/api/crm/customers/{$customer->id}/financial-summary")
            ->assertForbidden();
        $this->actingAs($user)->getJson("/api/crm/customers/{$customer->id}")
            ->assertOk()->assertJsonPath('data.permissions.can_view_financial', false);
    }

    public function test_orders_endpoint_is_paginated(): void
    {
        $branch = Branch::factory()->create();
        $customer = $this->customer($branch);
        $user = $this->user($branch, ['crm.access', 'crm.customer-orders.view']);

        $this->actingAs($user)->getJson("/api/crm/customers/{$customer->id}/orders")
            ->assertOk()->assertJsonStructure(['data' => ['data', 'current_page', 'per_page']]);
    }
}
