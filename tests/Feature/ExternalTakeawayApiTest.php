<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ExternalApiClient;
use App\Models\ExternalWebhookDelivery;
use App\Models\IntegrationOutbox;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TakeawayStatusEvent;
use App\Models\User;
use App\Services\CallCenter\OrderClosureService;
use App\Services\CallCenter\OrderStatusService;
use App\Services\Integration\TakeawayDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExternalTakeawayApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/external/takeaway';

    private Branch $branch;
    private User $manager;
    private ExternalApiClient $client;
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create(['name' => 'الفرع الرئيسي']);
        $this->manager = User::factory()->create(['branch_id' => $this->branch->id]);
        Role::findOrCreate('call-center-manager', 'web');
        $this->manager->assignRole('call-center-manager');
        [$this->client, $this->key] = ExternalApiClient::issue('Driver App');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── المصادقة ───────────────────────────────────────────────────────────────────

    public function test_requests_without_a_valid_active_key_are_rejected_with_the_unified_error_shape(): void
    {
        $this->getJson(self::BASE.'/orders')
            ->assertStatus(401)->assertExactJson(['error' => ['code' => 'unauthenticated', 'message' => 'Missing X-API-Key header.']]);

        $this->getJson(self::BASE.'/orders', ['X-API-Key' => 'o2tw_wrong'])
            ->assertStatus(401)->assertJsonPath('error.code', 'invalid_api_key');

        $this->client->update(['revoked_at' => now()]);
        $this->getJson(self::BASE.'/orders', ['X-API-Key' => $this->key])
            ->assertStatus(401)->assertJsonPath('error.code', 'invalid_api_key');
    }

    public function test_the_key_is_stored_hashed_never_in_plain_text(): void
    {
        $this->assertSame(hash('sha256', $this->key), $this->client->key_hash);
        $this->assertStringNotContainsString($this->key, json_encode(\DB::table('external_api_clients')->get()));
    }

    public function test_browser_origins_must_be_registered_for_the_key(): void
    {
        $this->getJson(self::BASE.'/orders', ['X-API-Key' => $this->key, 'Origin' => 'https://evil.test'])
            ->assertStatus(403)->assertJsonPath('error.code', 'origin_not_allowed');

        [, $browserKey] = ExternalApiClient::issue('Web Board', null, ['https://board.example.com']);
        $this->getJson(self::BASE.'/orders', ['X-API-Key' => $browserKey, 'Origin' => 'https://board.example.com'])
            ->assertOk()->assertHeader('Access-Control-Allow-Origin', 'https://board.example.com');
    }

    public function test_each_key_is_rate_limited(): void
    {
        [, $slowKey] = ExternalApiClient::issue('Slow App', null, [], 2);

        $this->getJson(self::BASE.'/orders', ['X-API-Key' => $slowKey])->assertOk();
        $this->getJson(self::BASE.'/orders', ['X-API-Key' => $slowKey])->assertOk();
        $this->getJson(self::BASE.'/orders', ['X-API-Key' => $slowKey])
            ->assertStatus(429)->assertJsonPath('error.code', 'rate_limited')->assertHeader('Retry-After');

        $this->getJson(self::BASE.'/orders', ['X-API-Key' => $this->key])->assertOk(); // مفتاح تاني ما بيتأثر
    }

    // ── القائمة والتفاصيل ──────────────────────────────────────────────────────────────

    public function test_only_closed_call_center_takeaway_and_delivery_orders_are_exposed(): void
    {
        $takeaway = $this->closedOrder();
        $delivery = $this->closedOrder(['order_type' => 'delivery']);
        $this->openOrder();                                            // لسا ما تسكّر
        $this->openOrder(['source' => 'pos', 'status' => 'closed']);   // مش كول سنتر
        $reopened = $this->closedOrder();
        app(OrderStatusService::class)->reopen($reopened, 'خطأ بالإغلاق');

        $ids = collect($this->api('GET', '/orders')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$takeaway->id, $delivery->id])->sort()->values()->all(), $ids);
    }

    public function test_the_order_contract_is_stable_and_leaks_no_internal_fields(): void
    {
        $order = $this->closedOrder(['order_type' => 'delivery', 'customer_name' => 'أحمد', 'customer_phone' => '0599000111',
            'note' => 'بدون بصل', 'delivery_address_snapshot' => ['city' => 'رام الله', 'street' => 'الإرسال', 'delivery_quote' => ['fee' => 10, 'zone_id' => 3]]]);

        $data = $this->api('GET', "/orders/{$order->id}")->assertOk()->json('data');

        $this->assertSame([
            'id', 'order_number', 'order_type', 'status', 'allowed_next_statuses', 'branch', 'customer', 'delivery', 'driver',
            'items', 'subtotal', 'discount', 'tax', 'delivery_fee', 'total', 'payment_status', 'payment_method', 'pickup_time',
            'notes', 'released_at', 'status_updated_at', 'created_at', 'updated_at',
        ], array_keys($data));
        $this->assertSame(['name', 'quantity', 'unit_price', 'total', 'modifiers', 'notes'], array_keys($data['items'][0]));
        $this->assertSame('new', $data['status']);
        $this->assertSame(['accepted'], $data['allowed_next_statuses']);
        $this->assertSame(['id' => $this->branch->id, 'name' => 'الفرع الرئيسي'], $data['branch']);
        $this->assertSame(['name' => 'أحمد', 'phone' => '0599000111'], $data['customer']);
        $this->assertSame(['city' => 'رام الله', 'street' => 'الإرسال'], $data['delivery']['address'], 'internal delivery quote stripped');
        $this->assertSame('paid', $data['payment_status']);
        $this->assertEquals(25.0, $data['total']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $data['created_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $data['released_at']);

        $json = json_encode($data);
        foreach (['public_ref', 'cashier', 'call_center_agent', 'closed_by', 'slot', 'cost', 'engine_discount', 'kitchen_release', 'delivery_quote'] as $internal) {
            $this->assertStringNotContainsString($internal, $json, "internal field leaked: {$internal}");
        }
    }

    public function test_list_filters_and_pagination(): void
    {
        Carbon::setTestNow('2027-02-10 08:00:00');
        $old = $this->closedOrder();
        Carbon::setTestNow('2027-02-10 10:00:00');
        $accepted = $this->closedOrder(['order_type' => 'delivery']);
        $this->api('PATCH', "/orders/{$accepted->id}/status", ['status' => 'accepted'])->assertOk();
        $other = Branch::factory()->create();
        $elsewhere = $this->closedOrder(['branch_id' => $other->id]);
        Carbon::setTestNow('2027-02-10 11:00:00');

        $ids = fn (string $query) => collect($this->api('GET', '/orders?'.$query)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$old->id, $elsewhere->id], $ids('status=new'));
        $this->assertSame([$accepted->id], $ids('status=accepted'));
        $this->assertSame([$accepted->id], $ids('order_type=delivery'));
        $this->assertSame([$elsewhere->id], $ids('branch_id='.$other->id));
        $this->assertEqualsCanonicalizing([$accepted->id, $elsewhere->id], $ids('from=2027-02-10T09:00:00Z'));
        $this->assertSame([$old->id], $ids('to=2027-02-10T09:00:00Z'));
        // polling: اللي تغيّر من هالوقت، تصاعدي بـ updated_at
        $this->assertSame([$accepted->id, $elsewhere->id], $ids('updated_since=2027-02-10T09:30:00Z'));

        $page = $this->api('GET', '/orders?per_page=2&page=2')->assertOk();
        $this->assertSame(['current_page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2], $page->json('meta'));
        $this->assertCount(1, $page->json('data'));
    }

    public function test_invalid_inputs_return_validation_errors_and_unknown_orders_404(): void
    {
        $this->api('GET', '/orders?status=cooking&per_page=500&updated_since=yesterday-ish')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['status', 'per_page', 'updated_since']]]);

        $this->api('GET', '/orders/999999')->assertStatus(404)->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Order not found.']]);

        $open = $this->openOrder();
        $this->api('GET', "/orders/{$open->id}")->assertStatus(404);
    }

    // ── تغيير الحالة والسائق ────────────────────────────────────────────────────────────

    public function test_a_takeaway_order_moves_step_by_step_and_ends_at_picked_up(): void
    {
        $order = $this->closedOrder();

        foreach (['accepted', 'preparing', 'ready', 'picked_up'] as $status) {
            $this->api('PATCH', "/orders/{$order->id}/status", ['status' => $status])->assertOk()->assertJsonPath('data.status', $status);
        }

        $this->api('PATCH', "/orders/{$order->id}/status", ['status' => 'delivered'])
            ->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition')->assertJsonPath('error.details.allowed_next_statuses', []);

        $fresh = $order->fresh();
        $this->assertSame('picked_up', $fresh->tw_status);
        $this->assertSame('closed', $fresh->status, 'the POS lifecycle is never touched by the external app');
        $this->assertSame(4, TakeawayStatusEvent::where('order_id', $order->id)->where('external_api_client_id', $this->client->id)->count());
    }

    public function test_invalid_and_forbidden_transitions(): void
    {
        $order = $this->closedOrder();

        $this->api('PATCH', "/orders/{$order->id}/status", ['status' => 'ready'])
            ->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition')
            ->assertJsonPath('error.details.current_status', 'new')
            ->assertJsonPath('error.details.allowed_next_statuses', ['accepted']);

        // الإلغاء مش من صلاحيات التطبيق الخارجي، ولا الرجوع لـ new
        foreach (['cancelled', 'new'] as $status) {
            $this->api('PATCH', "/orders/{$order->id}/status", ['status' => $status])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        }

        // نفس الحالة مرتين (retry) = no-op بدون حدث إضافي
        $this->api('PATCH', "/orders/{$order->id}/status", ['status' => 'accepted'])->assertOk();
        $this->api('PATCH', "/orders/{$order->id}/status", ['status' => 'accepted'])->assertOk();
        $this->assertSame(1, TakeawayStatusEvent::where('order_id', $order->id)->count());
    }

    public function test_delivery_orders_need_a_driver_before_pickup_and_end_at_delivered(): void
    {
        $order = $this->closedOrder(['order_type' => 'delivery']);
        foreach (['accepted', 'preparing', 'ready'] as $status) {
            $this->api('PATCH', "/orders/{$order->id}/status", ['status' => $status])->assertOk();
        }

        $this->api('PATCH', "/orders/{$order->id}/status", ['status' => 'picked_up'])
            ->assertStatus(409)->assertJsonPath('error.code', 'driver_required');

        $this->api('PATCH', "/orders/{$order->id}/driver", ['driver' => []])->assertStatus(422);
        $this->api('PATCH', "/orders/{$order->id}/driver", ['driver' => ['name' => 'سامر', 'phone' => '0598111222', 'external_id' => 'drv-7']])
            ->assertOk()
            ->assertJsonPath('data.driver.name', 'سامر')
            ->assertJsonPath('data.driver.external_id', 'drv-7');

        $this->api('PATCH', "/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
        $this->api('PATCH', "/orders/{$order->id}/driver", ['driver' => ['name' => 'غيره']])
            ->assertStatus(409)->assertJsonPath('error.code', 'driver_locked');
        $this->api('PATCH', "/orders/{$order->id}/status", ['status' => 'delivered'])
            ->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.allowed_next_statuses', []);
    }

    public function test_drivers_cannot_be_assigned_to_pickup_orders(): void
    {
        $order = $this->closedOrder();

        $this->api('PATCH', "/orders/{$order->id}/driver", ['driver' => ['name' => 'سامر']])
            ->assertStatus(409)->assertJsonPath('error.code', 'not_a_delivery_order');
    }

    // ── Webhooks ───────────────────────────────────────────────────────────────────

    public function test_changes_are_webhooked_to_other_clients_signed_but_not_echoed_to_their_author(): void
    {
        [$listener, , $secret] = ExternalApiClient::issue('Kitchen Board', 'https://board.test/hooks');
        $this->client->update(['webhook_url' => 'https://driver.test/hooks', 'webhook_secret' => 'driver-secret']);
        $order = $this->closedOrder();

        $this->api('PATCH', "/orders/{$order->id}/status", ['status' => 'accepted'])->assertOk();

        Http::fake(['*' => Http::response(['ok' => true])]);
        $this->artisan('takeaway:dispatch')->assertExitCode(0);

        // order.created → للاتنين. order.status_changed (عمله Driver App) → للـBoard بس
        $this->assertSame(2, ExternalWebhookDelivery::where('event_type', TakeawayDispatcher::EVENT_CREATED)->count());
        $changed = ExternalWebhookDelivery::where('event_type', TakeawayDispatcher::EVENT_STATUS_CHANGED)->get();
        $this->assertSame([$listener->id], $changed->pluck('external_api_client_id')->all());

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://board.test')
            && $request->hasHeader('X-Event', 'order.status_changed')
            && $request['data']['status'] === 'accepted'
            && $request->header('X-Signature')[0] === TakeawayDispatcher::sign($secret, $request->header('X-Timestamp')[0], $request->body()));
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://driver.test') && $request->hasHeader('X-Event', 'order.status_changed'));
    }

    public function test_reopening_an_order_withdraws_it_from_the_api(): void
    {
        $order = $this->closedOrder();
        $this->api('GET', "/orders/{$order->id}")->assertOk();

        app(OrderStatusService::class)->reopen($order->fresh(), 'إعادة فتح للتصحيح');

        $this->api('GET', "/orders/{$order->id}")->assertStatus(404);
        $this->assertSame(1, IntegrationOutbox::where('event_type', TakeawayDispatcher::EVENT_WITHDRAWN)->where('aggregate_ref', $order->public_ref)->count());
    }

    // ── أمر إدارة المفاتيح ─────────────────────────────────────────────────────────────

    public function test_the_artisan_command_creates_and_revokes_keys(): void
    {
        Artisan::call('external-api:client', ['action' => 'create', 'target' => 'Pickup App', '--webhook-url' => 'https://pickup.test/hook', '--rate' => 30]);
        preg_match('/X-API-Key:\s+(o2tw_\S+)/', Artisan::output(), $m);
        $this->assertNotEmpty($m[1] ?? null, 'the key is printed once');

        $created = ExternalApiClient::where('name', 'Pickup App')->firstOrFail();
        $this->assertSame(30, $created->rate_limit_per_minute);
        $this->assertNotNull($created->webhook_secret);
        $this->getJson(self::BASE.'/orders', ['X-API-Key' => $m[1]])->assertOk();

        $this->artisan('external-api:client', ['action' => 'revoke', 'target' => (string) $created->id])->assertExitCode(0);
        $this->getJson(self::BASE.'/orders', ['X-API-Key' => $m[1]])->assertStatus(401);
    }

    // ── helpers ────────────────────────────────────────────────────────────────────

    private function api(string $method, string $path, array $body = [])
    {
        return $this->json($method, self::BASE.$path, $body, ['X-API-Key' => $this->key]);
    }

    /** طلب كول سنتر مدفوع ومنفّذ ومغلق عبر المسار الحقيقي (F7) — فبيصير متاح للتيك أواي. */
    private function closedOrder(array $overrides = []): Order
    {
        $order = $this->openOrder($overrides + [
            'payment_status' => Order::PAYMENT_STATUS_PAID,
            'kitchen_release_status' => Order::KITCHEN_RELEASE_STATUS_RELEASED,
        ]);

        return app(OrderClosureService::class)->close($order, $this->manager)->fresh();
    }

    private function openOrder(array $overrides = []): Order
    {
        $order = Order::create($overrides + [
            'order_number' => 'ORD-'.now()->format('Ymd').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'branch_id' => $this->branch->id, 'order_type' => 'takeaway', 'source' => 'call_center', 'status' => 'pending',
            'customer_name' => 'عميل', 'customer_phone' => '0599000000', 'subtotal' => 25, 'total' => 25,
        ]);
        $item = Item::factory()->create();
        OrderItem::create([
            'order_id' => $order->id, 'item_id' => $item->id, 'department_id' => $item->department_id,
            'item_name' => $item->name, 'item_name_ar' => 'شاورما', 'quantity' => 1, 'price' => 25, 'total' => 25, 'status' => 'pending',
        ]);

        return $order;
    }
}
