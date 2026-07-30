<?php

namespace Tests\Feature;

use App\Models\{Branch, Customer, User};
use App\Services\CustomerIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CustomerResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_supported_phone_formats_resolve_the_same_customer(): void
    {
        [$user, $branch] = $this->operator();
        $customer = app(CustomerIdentityService::class)->create([
            'name' => 'عميل اختبار الكول سنتر',
            'phone' => '0599001122',
            'branch_id' => $branch->id,
        ]);

        foreach (['0599001122', '+970599001122', '970599001122', '599001122'] as $phone) {
            $this->actingAs($user)
                ->getJson('/api/call-center/customers/resolve-by-phone?phone='.urlencode($phone))
                ->assertOk()
                ->assertJsonPath('data.status', 'found')
                ->assertJsonPath('data.customer.id', $customer->id)
                ->assertJsonPath('data.normalized_phone', '+970599001122');
        }
    }

    public function test_not_found_and_multiple_are_distinct_results(): void
    {
        [$user, $branch] = $this->operator();
        $this->actingAs($user)->getJson('/api/call-center/customers/resolve-by-phone?phone=0598111222')
            ->assertOk()->assertJsonPath('data.status', 'not_found');

        Customer::create(['name' => 'One', 'code' => 'ONE', 'phone' => '598111222', 'branch_id' => $branch->id]);
        Customer::create(['name' => 'Two', 'code' => 'TWO', 'mobile' => '+970598111222', 'branch_id' => $branch->id]);

        $this->actingAs($user)->getJson('/api/call-center/customers/resolve-by-phone?phone=0598111222')
            ->assertOk()->assertJsonPath('data.status', 'multiple')->assertJsonCount(2, 'data.candidates');
    }

    private function operator(): array
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        Permission::findOrCreate('access-call-center-interface', 'web');
        $user->givePermissionTo('access-call-center-interface');
        return [$user, $branch];
    }
}
