<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Item;
use App\Models\Order;
use App\Models\StockBalance;
use App\Models\StockCount;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\AccountingDay;
use App\Services\Inventory\RecipeService;
use App\Services\Inventory\StockCountService;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockMovementLine;
use App\Services\Order\OrderPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * صفحة الأقسام: المبيعات والاستهلاك (من حركات "استهلاك بيع") والإحصائيات وجرد القسم.
 *
 * سندويش (10 ₪) = 1 خبز (0.5) + 120 غ دجاج (0.04) + 30 غ ثومية (= 6 غ ثوم × 0.01)  ← تكلفة 5.36
 * وجبة   (10 ₪) = 2 خبز + 250 غ دجاج                                               ← تكلفة 11
 *
 * طلب A (قبل تفعيل الخصم): وجبة ×1 — مبيعات بلا خصم.
 * طلب B: سندويش ×2 + وجبة ×1 ← مبيعات 30، تكلفة 10.72 + 11 = 21.72.
 */
class DepartmentReportTest extends TestCase
{
    use RefreshDatabase;

    private const PERMS = ['inventory.access', 'inventory.warehouses.all', 'inventory.costs.view', 'inventory.analytics.view',
        'inventory.settings.manage', 'inventory.counts.manage', 'inventory.counts.post', 'inventory.counts.reverse'];

    private User $admin;

    private Branch $branch;

    private Department $dept;

    private Warehouse $kitchen;

    private StockItem $bread;

    private StockItem $chicken;

    private Item $sandwich;

    private Item $meal;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::PERMS as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(self::PERMS);
        $this->today = AccountingDay::fromConfig()->of(now());

        $this->branch = Branch::factory()->create();
        $this->dept = Department::factory()->create(['nameAr' => 'شاورما', 'is_active' => true]);
        DB::table('branch_department')->insert(['branch_id' => $this->branch->id, 'department_id' => $this->dept->id, 'is_active' => true]);
        $this->kitchen = Warehouse::create(['name' => 'قسم الشاورما', 'code' => 'WH-SH', 'branch_id' => $this->branch->id, 'department_id' => $this->dept->id]);

        $unit = fn (string $n) => StockUnit::firstOrCreate(['name' => $n], ['is_active' => true]);
        $mk = fn (string $name, string $code, string $u, string $type = 'raw') => StockItem::create(['type' => $type, 'name' => $name, 'code' => $code, 'base_unit_id' => $unit($u)->id, 'is_stored' => true]);
        $this->bread = $mk('خبز صاج', 'RM-BRD', 'حبة');
        $this->chicken = $mk('صدر دجاج', 'RM-CHK', 'غرام');
        $garlic = $mk('ثوم', 'RM-GAR', 'غرام');
        $sauce = $mk('ثومية', 'IM-SAU', 'غرام', 'intermediate');

        $this->sandwich = Item::factory()->create(['name' => 'Sandwich', 'name_ar' => 'سندويش شاورما', 'department_id' => $this->dept->id]);
        $this->meal = Item::factory()->create(['name' => 'Meal', 'name_ar' => 'وجبة شاورما', 'department_id' => $this->dept->id]);

        $recipes = app(RecipeService::class);
        $recipes->save(null, $sauce->id, ['yield_quantity' => 1000, 'lines' => [['stock_item_id' => $garlic->id, 'quantity' => 200]]], $this->admin);
        $recipes->save($this->sandwich->id, null, ['lines' => [
            ['stock_item_id' => $this->bread->id, 'quantity' => 1],
            ['stock_item_id' => $this->chicken->id, 'quantity' => 120],
            ['stock_item_id' => $sauce->id, 'quantity' => 30],
        ]], $this->admin);
        $recipes->save($this->meal->id, null, ['lines' => [
            ['stock_item_id' => $this->bread->id, 'quantity' => 2],
            ['stock_item_id' => $this->chicken->id, 'quantity' => 250],
        ]], $this->admin);

        foreach ([[$this->bread, '100', '0.5'], [$this->chicken, '20000', '0.04'], [$garlic, '1000', '0.01']] as [$item, $qty, $cost]) {
            app(StockLedger::class)->post(new StockMovementLine($this->kitchen->id, $item->id, 'opening', $qty, unitCost: $cost, occurredAt: now()->subDay()));
        }
    }

    /** الطلب A قبل تفعيل الخصم، ثم الطلب B بعده. */
    private function sellBoth(): Order
    {
        $this->sell([[$this->meal, 1]]);
        $this->actingAs($this->admin)->putJson('/api/inventory/settings', ['deduct_sales' => true])->assertOk();
        $this->travel(1)->seconds();

        return $this->sell([[$this->sandwich, 2], [$this->meal, 1]]);
    }

    private function sell(array $lines): Order
    {
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'T-'.uniqid('', true), 'branch_id' => $this->branch->id, 'status' => 'served',
            'payment_status' => 'unpaid', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as [$item, $qty]) {
            DB::table('order_items')->insert([
                'order_id' => $orderId, 'item_id' => $item->id, 'department_id' => $item->department_id, 'item_name' => $item->name,
                'quantity' => $qty, 'price' => 10, 'total' => 10 * $qty, 'status' => 'served',
            ]);
        }

        return app(OrderPaymentService::class)->markPaid(Order::findOrFail($orderId));
    }

    private function q(array $extra = []): string
    {
        return http_build_query(['branch_id' => $this->branch->id, 'from' => $this->today, 'to' => $this->today, ...$extra]);
    }

    private function url(string $path, array $extra = []): string
    {
        return "/api/inventory/departments/{$this->dept->id}{$path}?".$this->q($extra);
    }

    private function summaryRow(?User $as = null): ?array
    {
        $rows = $this->actingAs($as ?? $this->admin)->getJson("/api/inventory/departments/summary?from={$this->today}&to={$this->today}")
            ->assertOk()->json('data.rows');

        return collect($rows)->firstWhere('department.id', $this->dept->id);
    }

    public function test_summary_shows_sales_cost_food_cost_and_undeducted_orders(): void
    {
        $this->sellBoth();

        $row = $this->summaryRow();
        $this->assertSame('40', $row['sales']);
        $this->assertSame('21.72', $row['cost']);
        $this->assertSame('54.30', $row['food_cost_percent']);
        $this->assertSame(2, $row['orders']);
        $this->assertSame(1, $row['undeducted_orders']);
        $this->assertSame($this->kitchen->id, $row['warehouse']['id']);
    }

    public function test_sale_items_with_their_consumed_materials(): void
    {
        $this->sellBoth();

        $rows = collect($this->actingAs($this->admin)->getJson($this->url('/sale-items'))->assertOk()->json('data.rows'))->keyBy('item_id');
        $sandwich = $rows[$this->sandwich->id];
        $meal = $rows[$this->meal->id];

        $this->assertSame(['2', '20', '10.72', '9.28', '50.00'], [$sandwich['quantity'], $sandwich['sales'], $sandwich['cost'], $sandwich['profit'], $sandwich['share_percent']]);
        $mats = collect($sandwich['materials'])->keyBy('stock_item_id');
        $this->assertCount(3, $mats);   // خبز + دجاج + ثوم (الثومية مفكوكة لموادها)
        $this->assertSame(['240', '9.6', 'غرام'], [$mats[$this->chicken->id]['quantity'], $mats[$this->chicken->id]['cost'], $mats[$this->chicken->id]['base_unit']]);
        // الوجبة بيعت مرتين لكن خُصمت مرة واحدة (الطلب A قبل التفعيل).
        $this->assertSame(['2', '20', '11'], [$meal['quantity'], $meal['sales'], $meal['cost']]);
    }

    public function test_consumption_per_material_matches_the_kardex(): void
    {
        $this->sellBoth();

        $rows = collect($this->actingAs($this->admin)->getJson($this->url('/consumption'))->assertOk()->json('data.rows'))->keyBy('stock_item_id');
        $chicken = $rows[$this->chicken->id];

        $this->assertSame(['490', '19.6', '0.04'], [$chicken['quantity'], $chicken['cost'], $chicken['unit_cost']]);
        $this->assertEqualsCanonicalizing([$this->meal->id => '250', $this->sandwich->id => '240'], collect($chicken['items'])->pluck('quantity', 'item_id')->all());
        // الرقم نفسه في كرت الصنف: مجموع حركات "استهلاك بيع" للدجاج في مخزن القسم.
        $this->assertEquals(-490, (float) StockMovement::where('type', 'sale')->where('warehouse_id', $this->kitchen->id)->where('stock_item_id', $this->chicken->id)->sum('quantity'));
    }

    public function test_cancelled_order_drops_out_of_sales_and_its_reversal_nets_the_cost(): void
    {
        $order = $this->sellBoth();
        Order::find($order->id)->update(['status' => 'cancelled']);

        $row = $this->summaryRow();
        $this->assertSame('10', $row['sales']);
        $this->assertSame('0', $row['cost']);
    }

    public function test_stats_totals_top_items_and_daily_series(): void
    {
        $this->sellBoth();

        $data = $this->actingAs($this->admin)->getJson($this->url('/stats'))->assertOk()->json('data');

        $t = $data['totals'];
        $this->assertSame(['40', 2, '20', '21.72', '18.28', '54.30'], [$t['sales'], $t['orders'], $t['avg_order'], $t['cost'], $t['profit'], $t['food_cost_percent']]);
        $this->assertCount(2, $data['top']);
        $this->assertSame([], $data['bottom']);   // صنفان فقط — "الأقل" هي القائمة نفسها
        $day = collect($data['daily'])->firstWhere('day', $this->today);
        $this->assertSame(['40', '21.72', 2], [$day['sales'], $day['cost'], $day['orders']]);
    }

    public function test_costs_are_hidden_without_cost_permission(): void
    {
        $this->sellBoth();
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['inventory.access', 'inventory.warehouses.all', 'inventory.analytics.view']);

        $sandwich = collect($this->actingAs($viewer)->getJson($this->url('/sale-items'))->assertOk()->json('data.rows'))->firstWhere('item_id', $this->sandwich->id);
        $this->assertSame('20', $sandwich['sales']);
        $this->assertNull($sandwich['cost']);
        $this->assertNull($sandwich['profit']);
        $this->assertNull($sandwich['materials'][0]['cost']);
    }

    public function test_department_is_hidden_and_forbidden_without_warehouse_access(): void
    {
        $other = Warehouse::create(['name' => 'مخزن آخر', 'code' => 'WH-X', 'branch_id' => $this->branch->id]);
        $user = User::factory()->create();
        $user->givePermissionTo(['inventory.access', 'inventory.analytics.view']);
        DB::table('warehouse_user')->insert(['warehouse_id' => $other->id, 'user_id' => $user->id]);

        $this->assertNull($this->summaryRow($user));
        $this->actingAs($user)->getJson($this->url('/sale-items'))->assertForbidden();
    }

    public function test_count_equation_draft_approve_and_reverse(): void
    {
        $this->sellBoth();
        $preview = fn () => collect($this->actingAs($this->admin)->getJson($this->url('/stock-count/preview'))->assertOk()->json('data.lines'))->keyBy('stock_item_id');

        // افتتاحي 20000 (قبل الفترة) − بيع 490 = متوقع 19510 = رصيد الكاردكس.
        $chicken = $preview()[$this->chicken->id];
        $this->assertSame(['20000', '490', '0', '19510'], [$chicken['opening'], $chicken['sale'], $chicken['transfers_in'], $chicken['expected']]);
        $this->assertSame('period', $chicken['start']['kind']);

        // التفصيل: حركتا بيع (سطر السندويش وسطر الوجبة) مجموعهما 490.
        $moves = $this->actingAs($this->admin)->getJson($this->url('/stock-count/movements', ['stock_item_id' => $this->chicken->id, 'column' => 'sale']))
            ->assertOk()->json('data');
        $this->assertCount(2, $moves['rows']);
        $this->assertSame('490', $moves['total']);

        // مسودة: الفعلي 19500 ← عجز 10 غ (0.4 ₪).
        $draft = $this->actingAs($this->admin)->postJson("/api/inventory/departments/{$this->dept->id}/stock-counts?".$this->q(), [
            'lines' => [['stock_item_id' => $this->chicken->id, 'quantity' => 19500], ['stock_item_id' => $this->bread->id, 'quantity' => null]],
        ])->assertCreated()->json('data');
        $this->assertSame('draft', $draft['status']);
        $line = collect($this->actingAs($this->admin)->getJson($this->url('/stock-count/preview', ['count_id' => $draft['id']]))->json('data.lines'))
            ->firstWhere('stock_item_id', $this->chicken->id);
        $this->assertSame(['19500', '-10', '-0.4', '-0.05'], [$line['counted'], $line['variance_quantity'], $line['variance_value'], $line['variance_percent']]);

        // الاعتماد بالمسار الموجود ← تسوية −10 في الكاردكس.
        $this->actingAs($this->admin)->postJson("/api/inventory/counts/{$draft['id']}/post")->assertOk();
        $balance = fn () => StockBalance::where('warehouse_id', $this->kitchen->id)->where('stock_item_id', $this->chicken->id)->value('quantity');
        $this->assertSame('19500.000000', $balance());
        $after = $preview()[$this->chicken->id];
        $this->assertSame(['count', '19500', '0', '19500'], [$after['start']['kind'], $after['opening'], $after['sale'], $after['expected']]);

        // المعتمد لا يُعدَّل…
        $this->actingAs($this->admin)->postJson("/api/inventory/departments/{$this->dept->id}/stock-counts?".$this->q(), [
            'count_id' => $draft['id'], 'lines' => [['stock_item_id' => $this->chicken->id, 'quantity' => 1]],
        ])->assertStatus(422);
        $posted = $this->actingAs($this->admin)->getJson($this->url("/stock-counts/{$draft['id']}"))->assertOk()->json('data');
        $this->assertSame('-10', $posted['lines'][0]['variance_quantity']);

        // …وتصحيحه بالعكس: تُعكس التسوية ويعود الرصيد ونقطة البداية.
        $this->actingAs($this->admin)->postJson("/api/inventory/counts/{$draft['id']}/reverse", ['reason' => 'خطأ عدّ'])->assertOk()
            ->assertJsonPath('data.status', 'reversed');
        $this->assertSame('19510.000000', $balance());
        $this->assertSame('period', $preview()[$this->chicken->id]['start']['kind']);
        $this->actingAs($this->admin)->postJson("/api/inventory/counts/{$draft['id']}/reverse", ['reason' => 'مرة ثانية'])->assertStatus(422);
        $this->actingAs($this->admin)->postJson("/api/inventory/counts/{$draft['id']}/post")->assertStatus(422);

        $history = $this->actingAs($this->admin)->getJson($this->url('/stock-counts'))->assertOk()->json('data.rows');
        $this->assertSame('reversed', $history[0]['status']);
        $this->assertCount(StockCount::count(), $history);
    }

    public function test_reversing_a_count_needs_its_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['inventory.access', 'inventory.warehouses.all', 'inventory.counts.manage', 'inventory.counts.post']);
        $count = app(StockCountService::class)->createFor($this->kitchen, collect([$this->chicken->id]), null, $this->admin);

        $this->actingAs($user)->postJson("/api/inventory/counts/{$count->id}/reverse", ['reason' => 'x'])->assertForbidden();
    }

    public function test_department_without_warehouse_shows_in_list_but_cannot_be_counted(): void
    {
        $bar = Department::factory()->create(['nameAr' => 'بار', 'is_active' => true]);
        DB::table('branch_department')->insert(['branch_id' => $this->branch->id, 'department_id' => $bar->id, 'is_active' => true]);

        $row = collect($this->actingAs($this->admin)->getJson('/api/inventory/departments/summary')->json('data.rows'))->firstWhere('department.id', $bar->id);
        $this->assertNotNull($row);
        $this->assertNull($row['warehouse']);
        $this->actingAs($this->admin)->getJson("/api/inventory/departments/{$bar->id}/stock-count/preview?branch_id={$this->branch->id}")->assertStatus(422);
    }

    public function test_exports_return_excel_files(): void
    {
        $this->sellBoth();
        foreach (['sale_items', 'consumption', 'count'] as $table) {
            $this->actingAs($this->admin)->get($this->url('/export', ['table' => $table]))->assertOk()
                ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
        $this->actingAs($this->admin)->get('/api/inventory/departments/summary/export')->assertOk();
    }
}
