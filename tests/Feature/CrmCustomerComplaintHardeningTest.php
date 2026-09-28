<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CrmStaffActivityLog;
use App\Models\Customer;
use App\Models\CustomerComplaint;
use App\Models\Order;
use App\Models\User;
use App\Notifications\ComplaintActivityNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Customers & complaints fixes: branch reach on complaint writes, the
 * customer's complaints tab (+ "elsewhere" counts), home branch surviving an
 * edit, cancelled orders out of purchase totals, branch filter gated by
 * permission, one arrival notification per person, and the staff activity log.
 */
class CrmCustomerComplaintHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function user(?Branch $branch, array $permissions): User
    {
        $user = User::factory()->create(['branch_id' => $branch?->id]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function customer(Branch $branch, array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'عميل اختبار',
            'code' => 'CRM-'.fake()->unique()->numerify('####'),
            'phone' => '599'.fake()->unique()->numerify('######'),
            'status' => 'active',
            'branch_id' => $branch->id,
        ], $overrides));
    }

    private function complaint(Customer $customer, Branch $branch, array $overrides = []): CustomerComplaint
    {
        return CustomerComplaint::create(array_merge([
            'customer_id' => $customer->id,
            'title' => 'شكوى',
            'description' => '',
            'status' => CustomerComplaint::STATUS_NEW,
            'priority' => 'normal',
            'severity' => 'info',
            'branch_id' => (string) $branch->id,
            'channel' => CustomerComplaint::CHANNEL_CRM,
        ], $overrides));
    }

    public function test_branch_user_cannot_update_other_branch_complaint_by_id_and_attempt_is_flagged(): void
    {
        $gaza = Branch::factory()->create();
        $nuseirat = Branch::factory()->create();
        $customer = $this->customer($gaza);
        $complaint = $this->complaint($customer, $gaza);
        $agent = $this->user($nuseirat, ['crm.access', 'crm.complaints.update', 'crm.complaints.view']);

        $this->actingAs($agent)->putJson("/api/crm/complaints/{$complaint->id}", ['status' => 'open'])->assertNotFound();

        $this->assertSame(CustomerComplaint::STATUS_NEW, $complaint->fresh()->status);
        $this->assertDatabaseHas('crm_staff_activity_logs', [
            'user_id' => $agent->id,
            'action' => 'complaint.access_denied',
            'subject_id' => $complaint->id,
            'is_flagged' => true,
        ]);
    }

    public function test_cross_branch_user_can_work_complaint_once_manager_assigns_it(): void
    {
        $gaza = Branch::factory()->create();
        $nuseirat = Branch::factory()->create();
        $customer = $this->customer($gaza);
        $agent = $this->user($nuseirat, ['crm.access', 'crm.complaints.update', 'crm.complaints.view']);
        $complaint = $this->complaint($customer, $gaza, ['assigned_user_id' => $agent->id]);

        $this->actingAs($agent)->putJson("/api/crm/complaints/{$complaint->id}", ['status' => 'open'])->assertOk();
        $this->actingAs($agent)->getJson("/api/crm/complaints/{$complaint->id}")->assertOk();
    }

    public function test_customer_complaints_tab_hides_other_branch_rows_but_reports_their_count(): void
    {
        $gaza = Branch::factory()->create();
        $nuseirat = Branch::factory()->create();
        $customer = $this->customer($gaza);
        $this->complaint($customer, $gaza, ['title' => 'شكوى غزة']);
        $this->complaint($customer, $nuseirat, ['title' => 'شكوى النصيرات']);
        $this->complaint($customer, $gaza, ['title' => 'حساسة', 'is_sensitive' => true]);
        $agent = $this->user($nuseirat, ['crm.access', 'crm.complaints.view']);

        $rows = $this->actingAs($agent)->getJson("/api/crm/customers/{$customer->id}/complaints")
            ->assertOk()->json('data.data');
        $this->assertSame(['شكوى النصيرات'], array_column($rows, 'title'));

        // Counted, not listed — and the sensitive one is not even counted.
        $this->actingAs($agent)->getJson("/api/crm/customers/{$customer->id}/complaints/elsewhere")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.open', 1)
            ->assertJsonPath('data.branches.0.branch_name', $gaza->name);
    }

    public function test_branch_employee_edit_keeps_customer_home_branch_and_is_logged_as_cross_branch(): void
    {
        $gaza = Branch::factory()->create();
        $nuseirat = Branch::factory()->create();
        $customer = $this->customer($gaza, ['name' => 'قديم']);
        $agent = $this->user($nuseirat, ['crm.access', 'crm.edit-customers']);

        $this->actingAs($agent)->putJson("/api/crm/customers/{$customer->id}", [
            'name' => 'جديد',
            'branch_id' => $nuseirat->id,
        ])->assertOk();

        $this->assertSame((int) $gaza->id, (int) $customer->fresh()->branch_id);

        $log = CrmStaffActivityLog::where('action', 'customer.updated')->where('customer_id', $customer->id)->firstOrFail();
        $this->assertTrue($log->is_flagged);
        $this->assertContains('cross_branch_customer_edit', $log->flags);
        $this->assertContains('identity_change', $log->flags);
        $this->assertSame('جديد', $log->details['changes']['name']['new']);
    }

    public function test_cancelled_orders_are_excluded_from_purchase_totals_and_counted_separately(): void
    {
        $branch = Branch::factory()->create();
        $customer = $this->customer($branch);
        foreach ([['served', 30], ['cancelled', 500], ['closed', 20]] as [$status, $total]) {
            Order::create([
                'order_number' => 'ORD-'.fake()->unique()->numerify('######'),
                'branch_id' => $branch->id, 'customer_id' => $customer->id,
                'order_type' => 'takeaway', 'source' => 'pos', 'status' => $status,
                'subtotal' => $total, 'total' => $total,
            ]);
        }
        $user = $this->user(null, ['crm.access', 'crm.view-customers']);

        $this->actingAs($user)->getJson("/api/crm/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.summary.total_purchases', 50)
            ->assertJsonPath('data.summary.average_order_value', 25)
            ->assertJsonPath('data.summary.completed_orders_count', 2)
            ->assertJsonPath('data.summary.cancelled_orders_count', 1);

        $row = collect($this->actingAs($user)->getJson('/api/crm/customers')->json('data.data'))->firstWhere('id', $customer->id);
        $this->assertEquals(50, $row['total_purchases']);
        $this->assertSame(1, $row['cancelled_orders_count']);
    }

    public function test_branch_filter_needs_its_permission(): void
    {
        $gaza = Branch::factory()->create();
        $nuseirat = Branch::factory()->create();
        $this->customer($gaza);
        $this->customer($nuseirat);

        $plain = $this->user(null, ['crm.access', 'crm.view-customers']);
        $this->actingAs($plain)->getJson("/api/crm/customers?branch_id={$gaza->id}")->assertOk()->assertJsonPath('data.total', 2);

        $manager = $this->user(null, ['crm.access', 'crm.view-customers', 'crm.customers.filter-by-branch']);
        $this->actingAs($manager)->getJson("/api/crm/customers?branch_id={$gaza->id}")->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_new_complaint_notifies_each_manager_exactly_once(): void
    {
        Notification::fake();
        $branch = Branch::factory()->create();
        $customer = $this->customer($branch);
        Permission::findOrCreate('crm.complaints.update', 'web');
        $managerRole = Role::findOrCreate('crm-manager', 'web');
        $managerRole->givePermissionTo('crm.complaints.update');
        $manager = User::factory()->create(['branch_id' => $branch->id]);
        $manager->assignRole($managerRole);
        $agent = $this->user($branch, ['crm.access', 'crm.complaints.create']);

        $this->actingAs($agent)->postJson("/api/crm/customers/{$customer->id}/complaints", [
            'title' => 'شكوى عادية',
        ])->assertCreated();

        Notification::assertSentToTimes($manager, ComplaintActivityNotification::class, 1);
    }

    public function test_staff_activity_log_is_manager_only(): void
    {
        $branch = Branch::factory()->create();
        $agent = $this->user($branch, ['crm.access']);
        $manager = $this->user($branch, ['crm.access', 'crm.staff-activity.view']);

        $this->actingAs($agent)->getJson('/api/crm/staff-activity')->assertForbidden();
        $this->actingAs($manager)->getJson('/api/crm/staff-activity?flagged=1')->assertOk();
    }
}
