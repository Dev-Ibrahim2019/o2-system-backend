<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Item;
use App\Models\StockItem;
use App\Models\StockItemUnit;
use App\Models\StockUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\AccountingDay;
use App\Services\Inventory\RecipeService;
use App\Services\Inventory\StockCountService;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockMovementLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * تحليلات المخزون: التحقق اليدوي من مثال صدر الدجاج.
 *
 * وصفة الشاورما العادي: 120 غ صدر دجاج + 30 غ ثومية (الثومية: 200 غ ثوم لكل 1000 غ).
 * وصفة الشاورما السوبر: 180 غ صدر دجاج.
 * مبيعات اليوم المدفوعة في قسم الشاورما: 50 عادي + 10 سوبر (+ سطر ملغى وطلب غير مدفوع لا يُحسبان).
 *   النظري للدجاج = 50 × 120 + 10 × 180 = 7800 غ
 * رصيد أمس 20 كغ بتكلفة 40 ₪/كغ، وجرد اليوم وجد 12 كغ:
 *   الفعلي = 20000 + 0 − 12000 = 8000 غ  → الفرق 200 غ = 8 ₪ = 2.56% (تحت حد 3%).
 */
class InventoryAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Branch $branch;

    private Warehouse $main;

    private Warehouse $shawarmaWh;

    private StockItem $chicken;

    private StockItem $garlic;

    private Item $regular;

    private Item $super;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $perms = ['inventory.access', 'inventory.warehouses.all', 'inventory.costs.view', 'inventory.analytics.view'];
        foreach ($perms as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo($perms);
        $this->today = AccountingDay::fromConfig()->of(now());

        $this->branch = Branch::factory()->create();
        $dept = Department::factory()->create();
        $this->main = Warehouse::create(['name' => 'مخزن النصيرات الرئيسي', 'code' => 'WH-M', 'branch_id' => $this->branch->id, 'is_default_sales' => true]);
        $this->shawarmaWh = Warehouse::create(['name' => 'قسم الشاورما', 'code' => 'WH-S', 'branch_id' => $this->branch->id, 'department_id' => $dept->id]);

        $gram = StockUnit::firstOrCreate(['name' => 'غرام'], ['is_active' => true]);
        $kg = StockUnit::firstOrCreate(['name' => 'كيلوغرام'], ['is_active' => true]);
        $this->chicken = StockItem::create(['type' => 'raw', 'name' => 'صدر دجاج', 'code' => 'RM-CHK', 'base_unit_id' => $gram->id, 'is_stored' => true, 'safety_stock' => 15000]);
        StockItemUnit::create(['stock_item_id' => $this->chicken->id, 'unit_id' => $kg->id, 'factor' => 1000]);
        $this->garlic = StockItem::create(['type' => 'raw', 'name' => 'ثوم', 'code' => 'RM-GAR', 'base_unit_id' => $gram->id, 'is_stored' => true]);
        $sauce = StockItem::create(['type' => 'intermediate', 'name' => 'ثومية', 'code' => 'IM-SAU', 'base_unit_id' => $gram->id, 'is_stored' => true]);

        $this->regular = Item::factory()->create(['name' => 'Shawarma', 'name_ar' => 'شاورما عادي', 'department_id' => $dept->id]);
        $this->super = Item::factory()->create(['name' => 'Super', 'name_ar' => 'شاورما سوبر', 'department_id' => $dept->id]);

        $recipes = app(RecipeService::class);
        $recipes->save(null, $sauce->id, ['yield_quantity' => 1000, 'lines' => [['stock_item_id' => $this->garlic->id, 'quantity' => 200]]], $this->admin);
        $recipes->save($this->regular->id, null, ['lines' => [
            ['stock_item_id' => $this->chicken->id, 'quantity' => 120],
            ['stock_item_id' => $sauce->id, 'quantity' => 30],
        ]], $this->admin);
        $recipes->save($this->super->id, null, ['lines' => [['stock_item_id' => $this->chicken->id, 'quantity' => 180]]], $this->admin);

        // رصيد أمس: 20 كغ دجاج بتكلفة 0.04 ₪/غ في قسم الشاورما.
        app(StockLedger::class)->post(new StockMovementLine(
            $this->shawarmaWh->id, $this->chicken->id, 'opening', '20000', unitCost: '0.04', occurredAt: now()->subDay(),
        ));

        $this->sale($this->regular, 30);
        $this->sale($this->regular, 20);
        $this->sale($this->super, 10);
        $this->sale($this->regular, 5, cancelled: true);
        $this->sale($this->regular, 7, paid: false);
    }

    private function sale(Item $item, int $qty, bool $cancelled = false, bool $paid = true, ?int $branchId = null): void
    {
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'T-'.uniqid('', true), 'branch_id' => $branchId ?? $this->branch->id, 'status' => 'closed',
            'payment_status' => $paid ? 'paid' : 'unpaid', 'paid_at' => $paid ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'item_id' => $item->id, 'department_id' => $item->department_id, 'item_name' => $item->name,
            'quantity' => $qty, 'price' => 10, 'total' => 10 * $qty, 'status' => $cancelled ? 'cancelled' : 'served',
        ]);
    }

    private function countChicken(string $grams): void
    {
        $service = app(StockCountService::class);
        $count = $service->create($this->shawarmaWh, null, null, $this->admin);
        $service->saveCounts($count, [['stock_item_id' => $this->chicken->id, 'entered_quantity' => $grams]], null);
        $service->post($count, $this->admin);
    }

    private function fetch(string $path, array $query = []): TestResponse
    {
        return $this->actingAs($this->admin)->getJson('/api/inventory/analytics/'.$path.'?'.http_build_query(['period' => 'today', ...$query]));
    }

    public function test_theoretical_vs_actual_for_chicken_breast_matches_manual_calculation(): void
    {
        $this->countChicken('12000');

        $rows = collect($this->fetch('variance')->assertOk()->json('data.rows'));
        $row = $rows->firstWhere('stock_item_id', $this->chicken->id);

        $this->assertSame('7800.000000', $row['theoretical_qty']);   // 50 × 120 + 10 × 180
        $this->assertSame('8000.000000', $row['actual_qty']);        // 20000 − 12000
        $this->assertSame('20000.000000', $row['opening']);
        $this->assertSame('12000.000000', $row['closing']);
        $this->assertSame('200.000000', $row['variance_qty']);
        $this->assertSame('8.00', $row['variance_value']);           // 200 × 0.04
        $this->assertSame('2.56', $row['variance_percent']);         // 200 ÷ 7800 × 100
        $this->assertFalse($row['alert']);
        $this->assertSame($this->today, $row['last_count_date']);
        $this->assertSame(['name' => 'كيلوغرام', 'factor' => 1000], $row['display_unit']);

        // الثوم من الوصفة الفرعية: 50 × 30 × (200 ÷ 1000) = 300 غ
        $this->assertSame('300.000000', $rows->firstWhere('stock_item_id', $this->garlic->id)['theoretical_qty']);
    }

    public function test_variance_above_threshold_raises_alert(): void
    {
        $this->countChicken('11000');   // فعلي 9000 مقابل نظري 7800 → 15.38%

        $row = collect($this->fetch('variance')->json('data.rows'))->firstWhere('stock_item_id', $this->chicken->id);
        $this->assertSame('15.38', $row['variance_percent']);
        $this->assertTrue($row['alert']);
    }

    public function test_sales_are_attributed_to_the_department_warehouse(): void
    {
        $this->assertNull(collect($this->fetch('variance', ['warehouse_id' => $this->main->id])->json('data.rows'))
            ->firstWhere('stock_item_id', $this->chicken->id));

        $wh = collect($this->fetch('variance', ['warehouse_id' => $this->shawarmaWh->id])->json('data.rows'))
            ->firstWhere('stock_item_id', $this->chicken->id);
        $this->assertSame('7800.000000', $wh['theoretical_qty']);

        // قسم غير مربوط → مخزن البيع الافتراضي للفرع.
        $this->shawarmaWh->update(['department_id' => null]);
        $main = collect($this->fetch('variance', ['warehouse_id' => $this->main->id])->json('data.rows'))
            ->firstWhere('stock_item_id', $this->chicken->id);
        $this->assertSame('7800.000000', $main['theoretical_qty']);
    }

    public function test_ingredient_drill_down_by_product(): void
    {
        $res = $this->fetch('ingredient', ['stock_item_id' => $this->chicken->id])->assertOk();
        $rows = collect($res->json('data.rows'))->keyBy('item_id');

        $this->assertSame('7800.000000', $res->json('data.total_consumed'));
        $this->assertSame('50.00', $rows[$this->regular->id]['sold']);
        $this->assertSame('120.000000', $rows[$this->regular->id]['per_unit']);
        $this->assertSame('6000.000000', $rows[$this->regular->id]['consumed']);
        $this->assertSame('240.00', $rows[$this->regular->id]['cost']);          // 6000 × 0.04
        $this->assertSame('76.92', $rows[$this->regular->id]['share_percent']);  // 6000 ÷ 7800
        $this->assertSame('1800.000000', $rows[$this->super->id]['consumed']);
    }

    public function test_product_drill_down_lists_ingredients_and_recipe_cost(): void
    {
        $res = $this->fetch('product', ['item_id' => $this->regular->id])->assertOk();
        $rows = collect($res->json('data.rows'))->keyBy('stock_item_id');

        $this->assertSame('50.00', $res->json('data.sold'));
        $this->assertSame('120.000000', $rows[$this->chicken->id]['per_unit']);
        $this->assertSame('6.000000', $rows[$this->garlic->id]['per_unit']);     // 30 × 200 ÷ 1000
        $this->assertSame('4.8000', $res->json('data.unit_cost'));               // 120 × 0.04 (الثوم بلا تكلفة)
    }

    public function test_overview_kpis_and_charts(): void
    {
        $this->countChicken('12000');
        $res = $this->fetch('overview')->assertOk();

        $this->assertSame('480.00', $res->json('data.kpis.stock_value'));           // 12000 × 0.04
        $this->assertSame('320.00', $res->json('data.kpis.actual_value'));          // 8000 × 0.04
        $this->assertSame(1, $res->json('data.kpis.below_safety'));                 // 12 كغ < 15 كغ
        $this->assertSame('قسم الشاورما', $res->json('data.value_by_warehouse.0.name'));
        $this->assertEquals([['day' => $this->today, 'value' => 480]], $res->json('data.value_trend'));
        $this->assertSame('صدر دجاج', $res->json('data.low_stock.0.name'));
        $this->assertSame('below', $res->json('data.low_stock.0.status'));
        $this->assertSame('18000.000000', $res->json('data.low_stock.0.suggested')); // 2 × 15000 − 12000
    }

    public function test_unattributed_sales_and_products_without_recipe_are_reported(): void
    {
        $noRecipe = Item::factory()->create(['name_ar' => 'فلافل']);
        $this->sale($noRecipe, 4);
        $this->sale($this->regular, 3, branchId: Branch::factory()->create()->id);   // فرع بلا مخازن

        $res = $this->fetch('variance')->assertOk();
        $this->assertSame('فلافل', $res->json('data.unrecipe.0.name'));
        $this->assertSame('3.00', $res->json('data.unattributed_qty'));
    }

    public function test_export_returns_an_xlsx_file(): void
    {
        $res = $this->actingAs($this->admin)->get('/api/inventory/analytics/export?period=today&table=variance');

        $res->assertOk();
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('PK', file_get_contents($res->baseResponse->getFile()->getPathname()));
    }

    public function test_demo_seeder_runs_and_is_repeatable(): void
    {
        $this->seed(\Database\Seeders\InventoryDemoSeeder::class);
        $this->seed(\Database\Seeders\InventoryDemoSeeder::class);

        $this->assertSame(28, DB::table('orders')->where('order_number', 'like', 'DEMO-INV-%')->count());   // 14 يوماً × منتجان
        $this->assertSame(1, \App\Models\StockCount::where('status', 'posted')->count());
        $this->assertSame(1, StockItem::where('code', 'DEMO-SAUCE')->count());
    }

    public function test_permissions_and_cost_hiding(): void
    {
        $noAccess = User::factory()->create();
        $noAccess->givePermissionTo(['inventory.access']);
        $this->actingAs($noAccess)->getJson('/api/inventory/analytics/overview')->assertForbidden();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['inventory.access', 'inventory.analytics.view', 'inventory.warehouses.all']);
        $res = $this->actingAs($viewer)->getJson('/api/inventory/analytics/variance?period=today')->assertOk();
        $row = collect($res->json('data.rows'))->firstWhere('stock_item_id', $this->chicken->id);
        $this->assertSame('7800.000000', $row['theoretical_qty']);
        $this->assertNull($row['variance_value']);
        $this->assertFalse($res->json('data.costs_visible'));
    }
}
