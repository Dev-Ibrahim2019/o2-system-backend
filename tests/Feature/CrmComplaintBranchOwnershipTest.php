<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerComplaint;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Covers the branch-ownership fix for complaints: auto-stamped branch_id,
 * cross-branch assignment gating, ownership staying put after a transfer,
 * and the ComplaintController::scoped() branch filter for customer-linked
 * complaints (previously a no-op — see git history on this file's sibling
 * changes for the "before" state).
 */
class CrmComplaintBranchOwnershipTest extends TestCase
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

    private function customer(Branch $branch): Customer
    {
        return Customer::create([
            'name' => 'عميل اختبار',
            'code' => 'CRM-'.fake()->unique()->numerify('####'),
            'phone' => '599'.fake()->unique()->numerify('######'),
            'status' => 'active',
            'branch_id' => $branch->id,
        ]);
    }

    private function order(Branch $branch, Customer $customer, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'ORD-'.fake()->unique()->numerify('######'),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'order_type' => 'takeaway',
            'source' => 'pos',
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'subtotal' => 10,
            'total' => 10,
        ], $overrides));
    }

    public function test_complaint_gets_order_branch_automatically(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $customer = $this->customer($branch);
        // Order belongs to otherBranch — the filing agent sits at $branch.
        $order = $this->order($otherBranch, $customer);
        $user = $this->user($branch, ['crm.access', 'crm.complaints.create']);

        $this->actingAs($user)->postJson("/api/crm/customers/{$customer->id}/complaints", [
            'title' => 'مشكلة بالطلب',
            'order_id' => $order->id,
        ])->assertCreated();

        $this->assertDatabaseHas('customer_complaints', [
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'branch_id' => (string) $otherBranch->id,
        ]);
    }

    public function test_complaint_without_order_gets_creator_branch(): void
    {
        $branch = Branch::factory()->create();
        $customer = $this->customer($branch);
        $user = $this->user($branch, ['crm.access', 'crm.complaints.create']);

        $this->actingAs($user)->postJson("/api/crm/customers/{$customer->id}/complaints", [
            'title' => 'شكوى عامة عن الخدمة',
        ])->assertCreated();

        $this->assertDatabaseHas('customer_complaints', [
            'customer_id' => $customer->id,
            'order_id' => null,
            'branch_id' => (string) $branch->id,
        ]);
    }

    public function test_complaint_can_be_assigned_cross_branch_only_when_authorized(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $customer = $this->customer($branch);
        $manager = $this->user($branch, ['crm.access', 'crm.complaints.update', 'crm.complaints.assign']);
        $otherBranchAgent = $this->user($otherBranch, ['crm.access']);

        $complaint = CustomerComplaint::create([
            'customer_id' => $customer->id,
            'title' => 'شكوى',
            'description' => '',
            'status' => CustomerComplaint::STATUS_NEW,
            'priority' => 'normal',
            'severity' => 'info',
            'branch_id' => (string) $branch->id,
            'channel' => CustomerComplaint::CHANNEL_CRM,
            'created_by' => $manager->id,
        ]);

        // No crm.complaints.assign-cross-branch yet: blocked.
        $this->actingAs($manager)->putJson("/api/crm/complaints/{$complaint->id}", [
            'assigned_user_id' => $otherBranchAgent->id,
        ])->assertForbidden();

        // Granted: allowed, and the complaint's own branch does not move.
        $manager->givePermissionTo('crm.complaints.assign-cross-branch');
        $this->actingAs($manager)->putJson("/api/crm/complaints/{$complaint->id}", [
            'assigned_user_id' => $otherBranchAgent->id,
        ])->assertOk();

        $this->assertDatabaseHas('customer_complaints', [
            'id' => $complaint->id,
            'assigned_user_id' => $otherBranchAgent->id,
            'branch_id' => (string) $branch->id, // origin branch unchanged
        ]);
    }

    public function test_assignment_history_is_preserved_with_branch_metadata(): void
    {
        $branch = Branch::factory()->create();
        $customer = $this->customer($branch);
        $manager = $this->user($branch, ['crm.access', 'crm.complaints.update', 'crm.complaints.assign']);
        $agent = $this->user($branch, ['crm.access']);

        $complaint = CustomerComplaint::create([
            'customer_id' => $customer->id,
            'title' => 'شكوى',
            'description' => '',
            'status' => CustomerComplaint::STATUS_NEW,
            'priority' => 'normal',
            'severity' => 'info',
            'branch_id' => (string) $branch->id,
            'channel' => CustomerComplaint::CHANNEL_CRM,
            'created_by' => $manager->id,
        ]);

        $this->actingAs($manager)->putJson("/api/crm/complaints/{$complaint->id}", [
            'assigned_user_id' => $agent->id,
        ])->assertOk();

        $this->assertDatabaseHas('complaint_followups', [
            'complaint_id' => $complaint->id,
            'action' => 'assigned',
        ]);
        $followup = \App\Models\ComplaintFollowup::where('complaint_id', $complaint->id)
            ->where('action', 'assigned')->firstOrFail();
        $this->assertSame($agent->id, $followup->metadata['to_user_id']);
        $this->assertSame($branch->id, $followup->metadata['to_branch_id']);
    }

    public function test_branch_scoped_user_does_not_see_other_branch_customer_linked_complaint(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        // Customer is intentionally global — both complaints belong to the
        // same customer, only their branch_id differs.
        $customer = $this->customer($branch);
        $user = $this->user($branch, ['crm.access', 'crm.complaints.view']);

        $ownComplaint = CustomerComplaint::create([
            'customer_id' => $customer->id, 'title' => 'شكوى فرعي', 'description' => '',
            'status' => CustomerComplaint::STATUS_NEW, 'priority' => 'normal', 'severity' => 'info',
            'branch_id' => (string) $branch->id, 'channel' => CustomerComplaint::CHANNEL_CRM,
        ]);
        $otherComplaint = CustomerComplaint::create([
            'customer_id' => $customer->id, 'title' => 'شكوى الفرع الآخر', 'description' => '',
            'status' => CustomerComplaint::STATUS_NEW, 'priority' => 'normal', 'severity' => 'info',
            'branch_id' => (string) $otherBranch->id, 'channel' => CustomerComplaint::CHANNEL_CRM,
        ]);

        $response = $this->actingAs($user)->getJson('/api/crm/complaints')->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id');

        $this->assertTrue($ids->contains($ownComplaint->id));
        $this->assertFalse($ids->contains($otherComplaint->id));
    }

    public function test_active_cross_branch_order_is_blocked(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $customer = $this->customer($branch);
        $this->order($otherBranch, $customer, ['status' => 'pending']);
        $user = $this->user($branch, [
            'crm.access', 'crm.customer-orders.view', 'crm.customer-orders.view-cross-branch',
        ]);

        // Even WITH the cross-branch permission, an active order from
        // another branch must never appear — only final-status ones do.
        $response = $this->actingAs($user)->getJson("/api/crm/customers/{$customer->id}/orders")->assertOk();
        $this->assertCount(0, $response->json('data.data'));
    }

    /**
     * A POS order at status='paid' is the shape a finished order actually
     * takes in practice — status='closed' exists in the schema but nothing
     * has written it on real data, so a filter that only accepted 'closed'
     * hid every genuinely-completed order.
     */
    public function test_paid_pos_order_from_other_branch_counts_as_finished(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $customer = $this->customer($branch);
        $order = $this->order($otherBranch, $customer, [
            'status' => 'paid', 'payment_status' => 'paid', 'source' => 'pos',
        ]);
        $user = $this->user($branch, [
            'crm.access', 'crm.customer-orders.view', 'crm.customer-orders.view-cross-branch',
        ]);

        $rows = $this->actingAs($user)
            ->getJson("/api/crm/customers/{$customer->id}/orders")->assertOk()->json('data.data');

        $this->assertCount(1, $rows);
        $this->assertSame($order->id, $rows[0]['id']);
        $this->assertTrue($rows[0]['is_other_branch_read_only']);
    }

    /**
     * The mirror case: a CALL CENTER order at status='paid' is paid up front
     * and still awaiting preparation, so it is active, not history — it must
     * never leak across branches.
     */
    public function test_paid_call_center_order_from_other_branch_is_still_active_and_hidden(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $customer = $this->customer($branch);
        $this->order($otherBranch, $customer, [
            'status' => 'paid', 'payment_status' => 'paid', 'source' => 'call_center',
        ]);
        $user = $this->user($branch, [
            'crm.access', 'crm.customer-orders.view', 'crm.customer-orders.view-cross-branch',
        ]);

        $rows = $this->actingAs($user)
            ->getJson("/api/crm/customers/{$customer->id}/orders")->assertOk()->json('data.data');

        $this->assertCount(0, $rows);
    }

    /**
     * The list showed the order but clicking it 404'd, because implicit
     * route-model binding re-applied BranchScope. Read must work end to end.
     */
    public function test_finished_cross_branch_order_details_are_readable(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $customer = $this->customer($branch);
        $order = $this->order($otherBranch, $customer, [
            'status' => 'paid', 'payment_status' => 'paid', 'source' => 'pos',
        ]);
        $user = $this->user($branch, [
            'crm.access', 'crm.customer-orders.view', 'crm.customer-orders.view-cross-branch',
        ]);

        $this->actingAs($user)->getJson("/api/crm/orders/{$order->id}")
            ->assertOk()->assertJsonPath('data.id', $order->id);
    }

    public function test_active_cross_branch_order_details_are_refused(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $customer = $this->customer($branch);
        $order = $this->order($otherBranch, $customer, ['status' => 'pending']);
        $user = $this->user($branch, [
            'crm.access', 'crm.customer-orders.view', 'crm.customer-orders.view-cross-branch',
        ]);

        $this->actingAs($user)->getJson("/api/crm/orders/{$order->id}")->assertNotFound();
    }

    /**
     * The profile header showed "إجمالي الطلبات 0" beside "إجمالي المشتريات
     * 50₪" for the same customer — counts came from loadCount() on the raw
     * relation while the sums had been widened for cross-branch reads.
     */
    public function test_profile_order_count_and_totals_agree_for_cross_branch_customer(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $customer = $this->customer($branch);
        $this->order($otherBranch, $customer, [
            'status' => 'paid', 'payment_status' => 'paid', 'source' => 'pos', 'total' => 50,
        ]);
        $user = $this->user($branch, [
            'crm.access', 'crm.view-customers', 'crm.customer-orders.view',
            'crm.customer-orders.view-cross-branch',
        ]);

        $body = $this->actingAs($user)->getJson("/api/crm/customers/{$customer->id}")
            ->assertOk()->json('data');

        $this->assertSame(1, $body['summary']['orders_count']);
        $this->assertEquals(50, $body['summary']['total_purchases']);
    }

    public function test_completed_cross_branch_order_is_read_only_visible_only_when_authorized(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $customer = $this->customer($branch);
        $order = $this->order($otherBranch, $customer, ['status' => 'closed']);

        $userWithoutPermission = $this->user($branch, ['crm.access', 'crm.customer-orders.view']);
        $response = $this->actingAs($userWithoutPermission)
            ->getJson("/api/crm/customers/{$customer->id}/orders")->assertOk();
        $this->assertCount(0, $response->json('data.data'));

        $userWithPermission = $this->user($branch, [
            'crm.access', 'crm.customer-orders.view', 'crm.customer-orders.view-cross-branch',
        ]);
        $response = $this->actingAs($userWithPermission)
            ->getJson("/api/crm/customers/{$customer->id}/orders")->assertOk();
        $rows = $response->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertSame($order->id, $rows[0]['id']);
        $this->assertTrue($rows[0]['is_other_branch_read_only']);
    }
}
