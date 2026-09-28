<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CrmOrderDelayAlert;
use App\Models\Customer;
use App\Models\CustomerOccasion;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OccasionTodayNotification;
use App\Notifications\OrderDelayedNotification;
use App\Services\Crm\CrmOccasionReminderService;
use App\Services\Crm\CrmOrderDelayAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Occasions: assignee kept on customer occasions, null-on-update is a 422,
 * SQL pre-filter agrees with nextOccurrence(), daily reminders.
 * Orders: "active"/"delayed" follow Order::scopeFinalized(), and delay
 * alerts neither flood on stale orders nor re-page on a threshold change.
 */
class CrmOccasionsOrdersFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(?Branch $branch, array $permissions): User
    {
        $user = User::factory()->create(['branch_id' => $branch?->id]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function customer(Branch $branch): Customer
    {
        return Customer::create([
            'name' => 'عميل مناسبات',
            'code' => 'CRM-'.fake()->unique()->numerify('####'),
            'phone' => '599'.fake()->unique()->numerify('######'),
            'status' => 'active',
            'branch_id' => $branch->id,
        ]);
    }

    private function occasion(Customer $customer, string $date, bool $annual = true, array $extra = []): CustomerOccasion
    {
        return $customer->occasions()->create(array_merge([
            'occasion_type' => 'birthday',
            'title' => 'عيد ميلاد',
            'date' => $date,
            'repeats_annually' => $annual,
            'is_active' => true,
        ], $extra));
    }

    private function order(Branch $branch, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'ORD-'.fake()->unique()->numerify('######'),
            'branch_id' => $branch->id,
            'order_type' => 'takeaway',
            'source' => 'pos',
            'status' => 'pending',
            'subtotal' => 10,
            'total' => 10,
        ], $overrides));
    }

    // ── Occasions ──

    public function test_customer_occasion_keeps_its_assignee(): void
    {
        $branch = Branch::factory()->create();
        $customer = $this->customer($branch);
        $assignee = $this->user($branch, ['crm.occasions.update']);
        $agent = $this->user($branch, ['crm.access', 'crm.occasions.create', 'crm.occasions.update']);

        $id = $this->actingAs($agent)->postJson("/api/crm/customers/{$customer->id}/occasions", [
            'occasion_type' => 'anniversary', 'title' => 'ذكرى', 'date' => '2020-05-01',
            'assigned_user_id' => $assignee->id,
        ])->assertCreated()->json('data.id');

        $this->assertSame($assignee->id, (int) CustomerOccasion::find($id)->assigned_user_id);

        $this->actingAs($agent)->putJson("/api/crm/customers/{$customer->id}/occasions/{$id}", ['assigned_user_id' => null])->assertOk();
        $this->assertNull(CustomerOccasion::find($id)->assigned_user_id);
    }

    public function test_updating_occasion_with_null_title_is_rejected_not_a_server_error(): void
    {
        $branch = Branch::factory()->create();
        $customer = $this->customer($branch);
        $occasion = $this->occasion($customer, '2020-05-01');
        $agent = $this->user($branch, ['crm.access', 'crm.occasions.update']);

        $this->actingAs($agent)->putJson("/api/crm/customers/{$customer->id}/occasions/{$occasion->id}", ['title' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->actingAs($agent)->putJson("/api/crm/customers/{$customer->id}/occasions/{$occasion->id}", ['notes' => 'x'])
            ->assertOk();
    }

    public function test_occasion_window_prefilter_matches_next_occurrence_including_new_year_wrap(): void
    {
        Carbon::setTestNow('2026-12-30 10:00:00');
        $branch = Branch::factory()->create();
        $customer = $this->customer($branch);
        $inWrap = $this->occasion($customer, '1990-01-02');          // annual, lands Jan 2 next year
        $today = $this->occasion($customer, '1985-12-30');           // annual, today
        $past = $this->occasion($customer, '2026-12-01', false);     // one-off, gone
        $later = $this->occasion($customer, '1990-03-15');           // annual, outside a week

        $ids = CustomerOccasion::query()
            ->mayOccurBetween(now()->startOfDay(), now()->copy()->addDays(6)->endOfDay())
            ->pluck('id')->all();

        $this->assertContains($inWrap->id, $ids);
        $this->assertContains($today->id, $ids);
        $this->assertNotContains($past->id, $ids);
        $this->assertNotContains($later->id, $ids);

        $user = $this->user(null, ['crm.access', 'crm.occasions.view']);
        $this->actingAs($user)->getJson('/api/crm/occasions/summary')
            ->assertOk()->assertJsonPath('data.today', 1);
        $rows = $this->actingAs($user)->getJson('/api/crm/occasions?range=today')->assertOk()->json('data');
        $this->assertSame([$today->id], array_column($rows, 'id'));
    }

    public function test_daily_reminder_goes_to_assignee_or_managers_once(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-06-10 09:00:00');
        $branch = Branch::factory()->create();
        $customer = $this->customer($branch);
        $assignee = User::factory()->create(['branch_id' => $branch->id]);
        $manager = User::factory()->create(['branch_id' => $branch->id]);
        $manager->assignRole(Role::findOrCreate('crm-manager', 'web'));

        $this->occasion($customer, '1990-06-10', true, ['assigned_user_id' => $assignee->id]);
        $this->occasion($customer, '2000-06-10', true, ['title' => 'غير مسندة']);
        $this->occasion($customer, '1990-06-11'); // tomorrow — not today

        $this->assertSame(2, app(CrmOccasionReminderService::class)->sendDueToday());

        Notification::assertSentToTimes($assignee, OccasionTodayNotification::class, 1);
        Notification::assertSentToTimes($manager, OccasionTodayNotification::class, 1);
    }

    // ── Orders ──

    public function test_active_and_delayed_follow_finalized_definition(): void
    {
        $branch = Branch::factory()->create();
        $old = now()->subHours(2);
        $closed = $this->order($branch, ['status' => 'closed']);
        $delivered = $this->order($branch, ['status' => 'DELIVERED']);
        $pending = $this->order($branch, ['status' => 'pending']);
        $ccPaid = $this->order($branch, ['status' => 'paid', 'source' => 'call_center']); // paid before kitchen
        $posPaid = $this->order($branch, ['status' => 'paid', 'source' => 'pos']);
        $scheduled = $this->order($branch, ['status' => 'scheduled']);
        Order::withoutGlobalScopes()->whereKey([$closed->id, $delivered->id, $pending->id, $ccPaid->id, $posPaid->id, $scheduled->id])
            ->update(['created_at' => $old]);

        $user = $this->user(null, ['crm.access', 'crm.customer-orders.view']);

        $active = array_column($this->actingAs($user)->getJson('/api/crm/orders?active=1')->assertOk()->json('data.data'), 'id');
        sort($active);
        $expected = [$pending->id, $ccPaid->id, $scheduled->id];
        sort($expected);
        $this->assertSame($expected, $active);

        $delayed = array_column($this->actingAs($user)->getJson('/api/crm/orders/delayed?minutes=30')->assertOk()->json('data.data'), 'id');
        sort($delayed);
        $expectedDelayed = [$pending->id, $ccPaid->id];
        sort($expectedDelayed);
        $this->assertSame($expectedDelayed, $delayed);
    }

    public function test_delay_alerts_skip_stale_orders_and_do_not_repage_on_threshold_change(): void
    {
        Notification::fake();
        $branch = Branch::factory()->create();
        $watcher = $this->user(null, ['crm.customer-orders.view']);

        $live = $this->order($branch);
        $stale = $this->order($branch);
        $closed = $this->order($branch, ['status' => 'closed']);
        Order::withoutGlobalScopes()->whereKey([$live->id, $closed->id])->update(['created_at' => now()->subMinutes(45)]);
        Order::withoutGlobalScopes()->whereKey($stale->id)->update(['created_at' => now()->subDays(3)]);

        $service = app(CrmOrderDelayAlertService::class);
        $this->assertSame(1, $service->checkAndNotify());
        Notification::assertSentToTimes($watcher, OrderDelayedNotification::class, 1);

        // Threshold changes: the order already alerted is not paged again.
        \App\Models\CrmOrderDelaySetting::query()->update(['threshold_minutes' => 40]);
        $this->assertSame(0, $service->checkAndNotify());
        $this->assertSame(1, CrmOrderDelayAlert::count());
    }
}
