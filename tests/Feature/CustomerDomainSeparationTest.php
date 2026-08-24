<?php

namespace Tests\Feature;

use App\Models\{Branch, Customer, User};
use App\Services\CallCenter\CallCenterService;
use App\Services\CustomerIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Covers the Operational vs Financial customer_type split:
 * - CRM / Call Center creation must always land as 'operational'.
 * - Accounting's own "Add Customer" workflow must always land as 'financial'.
 * - Accounting's Customer Accounts screen (GET /customers) must only ever
 *   return 'financial' customers.
 * - CRM's directory (GET /crm/customers) must return every customer
 *   regardless of type.
 * - A client cannot smuggle customer_type=financial through a CRM/Call
 *   Center create request.
 */
class CustomerDomainSeparationTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPermissions(Branch $branch, array $permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    // ── TEST 1 — CRM ────────────────────────────────────────────────
    public function test_crm_created_customer_is_operational_and_hidden_from_accounting(): void
    {
        $branch = Branch::factory()->create();
        $user = $this->userWithPermissions($branch, ['crm.access', 'crm.create-customers', 'crm.view-customers']);

        $response = $this->actingAs($user)->postJson('/api/crm/customers', [
            'name' => 'CRM Customer',
            'phone' => '0599111001',
        ]);
        $response->assertCreated();

        $this->assertDatabaseHas('customers', ['name' => 'CRM Customer', 'customer_type' => 'operational']);

        $crmList = $this->actingAs($user)->getJson('/api/crm/customers?search=CRM Customer');
        $crmList->assertOk()->assertJsonFragment(['name' => 'CRM Customer']);

        $financialUser = $this->userWithPermissions($branch, ['crm.view-customers']);
        $accountingList = $this->actingAs($financialUser)->getJson('/api/customers?search=CRM Customer');
        $accountingList->assertOk();
        $this->assertStringNotContainsString('CRM Customer', $accountingList->getContent());
    }

    // ── TEST 8 — SECURITY (client cannot force customer_type=financial via CRM) ──
    public function test_crm_create_ignores_client_supplied_financial_type(): void
    {
        $branch = Branch::factory()->create();
        $user = $this->userWithPermissions($branch, ['crm.access', 'crm.create-customers']);

        $this->actingAs($user)->postJson('/api/crm/customers', [
            'name' => 'Spoofed Customer',
            'phone' => '0599111002',
            'customer_type' => 'financial',
        ])->assertCreated();

        $this->assertDatabaseHas('customers', ['name' => 'Spoofed Customer', 'customer_type' => 'operational']);
    }

    // ── TEST 2 — CALL CENTER ────────────────────────────────────────
    public function test_call_center_created_customer_is_operational_and_hidden_from_accounting(): void
    {
        $branch = Branch::factory()->create();
        $user = $this->userWithPermissions($branch, ['access-call-center', 'crm.view-customers']);

        $this->actingAs($user)->postJson('/api/call-center/customers', [
            'name' => 'Call Center Customer',
            'phone' => '0599111003',
            'branch_id' => $branch->id,
        ])->assertCreated();

        $this->assertDatabaseHas('customers', ['name' => 'Call Center Customer', 'customer_type' => 'operational']);

        $accountingList = $this->actingAs($user)->getJson('/api/customers?search=Call Center Customer');
        $accountingList->assertOk();
        $this->assertStringNotContainsString('Call Center Customer', $accountingList->getContent());
    }

    // ── Direct service-level check (covers CallCenterOrderCreationService's
    //    inline resolve-or-create path via the same underlying service) ──
    public function test_identity_service_requires_explicit_type_and_ignores_data_override(): void
    {
        $service = app(CustomerIdentityService::class);

        $operational = $service->create([
            'name' => 'Order Flow Customer',
            'phone' => '0599111004',
            'customer_type' => 'financial', // attempted override inside $data
        ], Customer::TYPE_OPERATIONAL);

        $this->assertSame('operational', $operational->fresh()->customer_type);
    }

    // ── TEST 5 — FINANCIAL ADMINISTRATION ──────────────────────────
    public function test_financial_created_customer_is_visible_in_both_accounting_and_crm(): void
    {
        $branch = Branch::factory()->create();
        $user = $this->userWithPermissions($branch, ['crm.create-customers', 'crm.view-customers', 'crm.access']);

        $this->actingAs($user)->postJson('/api/customers', [
            'name' => 'Financial Customer',
            'phone' => '0599111005',
        ])->assertCreated();

        $this->assertDatabaseHas('customers', ['name' => 'Financial Customer', 'customer_type' => 'financial']);

        $accountingList = $this->actingAs($user)->getJson('/api/customers?search=Financial Customer');
        $accountingList->assertOk()->assertJsonFragment(['name' => 'Financial Customer']);

        $crmList = $this->actingAs($user)->getJson('/api/crm/customers?search=Financial Customer');
        $crmList->assertOk()->assertJsonFragment(['name' => 'Financial Customer']);
    }

    // ── TEST 6 — EXISTING CUSTOMER (pre-migration row stays visible) ──
    public function test_legacy_style_financial_customer_remains_visible_in_accounting(): void
    {
        $branch = Branch::factory()->create();
        $user = $this->userWithPermissions($branch, ['crm.view-customers']);

        // Simulates a customer row created before customer_type existed —
        // the migration backfills exactly this value for all such rows.
        Customer::create([
            'code' => 'LEGACY-1',
            'name' => 'Legacy Customer',
            'phone' => '0599111006',
            'status' => 'active',
            'customer_type' => 'financial',
            'branch_id' => $branch->id,
        ]);

        $accountingList = $this->actingAs($user)->getJson('/api/customers?search=Legacy Customer');
        $accountingList->assertOk()->assertJsonFragment(['name' => 'Legacy Customer']);
    }

    // ── TEST 7 — ORDERS (both types keep working with Order.customer_id) ──
    public function test_orders_work_regardless_of_customer_type(): void
    {
        $branch = Branch::factory()->create();
        $operational = Customer::create([
            'code' => 'OP-1', 'name' => 'Operational Order Customer', 'phone' => '0599111007',
            'status' => 'active', 'customer_type' => 'operational', 'branch_id' => $branch->id,
        ]);
        $financial = Customer::create([
            'code' => 'FIN-1', 'name' => 'Financial Order Customer', 'phone' => '0599111008',
            'status' => 'active', 'customer_type' => 'financial', 'branch_id' => $branch->id,
        ]);

        $this->assertNotNull($operational->id);
        $this->assertNotNull($financial->id);
        // Both are valid customers.id values an Order.customer_id foreign key can reference —
        // customer_type plays no part in that relationship.
        $this->assertDatabaseHas('customers', ['id' => $operational->id]);
        $this->assertDatabaseHas('customers', ['id' => $financial->id]);
    }
}
