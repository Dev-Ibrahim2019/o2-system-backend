<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\AccountingDay;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockMovementLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** أرصدة المخازن في تاريخ، وكرت الصنف بلا مخزن، وفهرس الأصناف بالرصيد وشجرة التصنيفات. */
class StockReportTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = [
        'inventory.access', 'inventory.warehouses.all', 'inventory.costs.view',
        'inventory.documents.manage', 'inventory.documents.post',
    ];

    private User $admin;

    private Warehouse $store;

    private Warehouse $kitchen;

    private StockItem $rice;

    private StockItem $oil;

    private StockCategory $food;

    private StockCategory $grains;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->user(self::ADMIN);
        $this->store = Warehouse::create(['name' => 'المخزن الرئيسي', 'code' => 'WH-MAIN', 'branch_id' => Branch::factory()->create()->id]);
        $this->kitchen = Warehouse::create(['name' => 'المطبخ', 'code' => 'WH-KIT', 'branch_id' => Branch::factory()->create()->id]);
        $kg = StockUnit::firstOrCreate(['name' => 'كيلوغرام'], ['is_active' => true]);
        $this->food = StockCategory::create(['name' => 'مواد غذائية', 'is_active' => true]);
        $this->grains = StockCategory::create(['name' => 'حبوب', 'parent_id' => $this->food->id, 'is_active' => true]);
        $this->rice = StockItem::create(['type' => 'raw', 'name' => 'أرز', 'code' => 'A-RICE', 'base_unit_id' => $kg->id, 'is_stored' => true]);
        $this->oil = StockItem::create(['type' => 'raw', 'name' => 'زيت', 'code' => 'B-OIL', 'base_unit_id' => $kg->id, 'is_stored' => true]);
        StockItem::create(['type' => 'raw', 'name' => 'قديم', 'code' => 'Z-OLD', 'base_unit_id' => $kg->id, 'is_stored' => true, 'is_active' => false]);
        $this->rice->categories()->attach($this->grains->id);
        $this->oil->categories()->attach($this->food->id);
        $this->today = AccountingDay::fromConfig()->of(now());

        // قبل 5 أيام: رصيد أول مدة 100 @2، قبل يومين: شراء 50 @3، اليوم: صرف 30 للمطبخ.
        $ledger = app(StockLedger::class);
        $ledger->post(new StockMovementLine($this->store->id, $this->rice->id, 'opening', '100', unitCost: '2', occurredAt: now()->subDays(5)), $this->admin);
        $ledger->post(new StockMovementLine($this->store->id, $this->rice->id, 'purchase', '50', unitCost: '3', occurredAt: now()->subDays(2)), $this->admin);
        $id = $this->actingAs($this->admin)->postJson('/api/inventory/documents', [
            'type' => 'transfer', 'warehouse_id' => $this->store->id, 'to_warehouse_id' => $this->kitchen->id,
            'lines' => [['stock_item_id' => $this->rice->id, 'quantity' => 30]],
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->admin)->postJson("/api/inventory/documents/{$id}/post")->assertOk();
    }

    private function user(array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ($permissions as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function report(array $query = [], ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->admin)->getJson('/api/inventory/reports/balances?'.http_build_query($query))->assertOk()->json('data');
    }

    public function test_balance_as_of_a_date_comes_from_the_ledger(): void
    {
        $now = $this->report();
        $this->assertSame(['A-RICE'], array_column($now['rows'], 'code'));
        $this->assertSame('150.000000', $now['rows'][0]['quantity']);
        // الصرف ينقل القيمة كما هي: 100×2 + 50×3
        $this->assertSame('350.000000', $now['rows'][0]['value']);

        $before = $this->report(['to' => now()->subDays(3)->toDateString()]);
        $this->assertSame('100.000000', $before['rows'][0]['quantity']);
        $this->assertSame('2.000000', $before['rows'][0]['unit_cost']);

        $range = $this->report(['from' => now()->subDays(3)->toDateString(), 'to' => $this->today]);
        $this->assertSame('100.000000', $range['rows'][0]['opening']);
        $this->assertSame('80.000000', $range['rows'][0]['quantity_in']); // شراء 50 + وارد المطبخ 30
        $this->assertSame('30.000000', $range['rows'][0]['quantity_out']);
    }

    public function test_last_purchase_valuation_and_report_types(): void
    {
        $last = $this->report(['valuation' => 'last']);
        $this->assertSame('3.000000', $last['rows'][0]['unit_cost']);
        $this->assertSame('450.000000', $last['rows'][0]['value']);

        $matrix = $this->report(['report' => 'matrix']);
        $this->assertSame('120.000000', $matrix['rows'][0]['by_warehouse'][$this->store->id]);
        $this->assertSame('30.000000', $matrix['rows'][0]['by_warehouse'][$this->kitchen->id]);

        $sheet = $this->report(['report' => 'count_sheet']);
        $this->assertSame([['المخزن الرئيسي', '120.000000'], ['المطبخ', '30.000000']],
            array_map(fn ($r) => [$r['warehouse'], $r['quantity']], $sheet['rows']));

        // الأصفار عند الطلب (الزيت بلا حركة)، والموقوف لا يظهر إلا بالحالة
        $this->assertSame(['A-RICE', 'B-OIL'], array_column($this->report(['zeros' => 1])['rows'], 'code'));
        $this->assertSame(['A-RICE', 'B-OIL', 'Z-OLD'], array_column($this->report(['zeros' => 1, 'status' => 'all'])['rows'], 'code'));
        $this->assertSame(['B-OIL'], array_column($this->report(['zeros' => 1, 'code_from' => 'B', 'code_to' => 'C'])['rows'], 'code'));

        $byCategory = $this->report(['report' => 'category', 'zeros' => 1]);
        $this->assertSame(['حبوب', 'مواد غذائية'], array_column($byCategory['rows'], 'category'));

        // التصنيف يشمل ما تحته
        $this->assertSame(['A-RICE', 'B-OIL'], array_column($this->report(['zeros' => 1, 'category_id' => $this->food->id])['rows'], 'code'));
        $this->assertSame(['A-RICE'], array_column($this->report(['zeros' => 1, 'category_id' => $this->grains->id])['rows'], 'code'));
    }

    public function test_report_is_limited_to_the_users_warehouses_and_hides_costs(): void
    {
        $keeper = $this->user(['inventory.access']);
        $this->kitchen->users()->attach($keeper->id, ['created_at' => now()]);

        $rows = $this->report([], $keeper)['rows'];
        $this->assertSame('30.000000', $rows[0]['quantity']);
        $this->assertNull($rows[0]['value']);

        $this->actingAs($keeper)->getJson('/api/inventory/reports/balances?'.http_build_query(['warehouse_ids' => [$this->store->id]]))->assertForbidden();
    }

    public function test_kardex_without_warehouse_spans_all_warehouses_with_sources_and_accounts(): void
    {
        $data = $this->actingAs($this->admin)->getJson("/api/inventory/kardex?stock_item_id={$this->rice->id}&from=".now()->subDays(10)->toDateString())
            ->assertOk()->json('data');

        $this->assertNull($data['warehouse']);
        $this->assertSame('150.000000', $data['totals']['closing_quantity']);
        $rows = collect($data['rows']);
        $this->assertSame(['opening', 'purchase', 'transfer_out', 'transfer_in'], $rows->pluck('type')->all());
        $this->assertSame(['100.000000', '150.000000', '120.000000', '150.000000'], $rows->pluck('running_quantity')->all());

        $out = $rows->firstWhere('type', 'transfer_out');
        $this->assertSame('المخزن الرئيسي', $out['warehouse']['name']);
        $this->assertSame('30.000000', $out['quantity_out']);
        $this->assertSame('المطبخ', $out['account_name']);
        $this->assertStringStartsWith('TRF-', $out['number']);
        $this->assertSame(['kind' => 'document', 'type' => 'transfer'], array_intersect_key($out['source'], ['kind' => 1, 'type' => 1]));
        $this->assertSame('المخزن الرئيسي', $rows->firstWhere('type', 'transfer_in')['account_name']);
        $this->assertSame('رصيد أول المدة', $rows->firstWhere('type', 'opening')['account_name']);

        // مع المخزن: كما كان
        $store = $this->actingAs($this->admin)->getJson("/api/inventory/kardex?stock_item_id={$this->rice->id}&warehouse_id={$this->store->id}&from=".now()->subDays(10)->toDateString())->json('data');
        $this->assertSame('120.000000', $store['totals']['closing_quantity']);
    }

    public function test_item_index_returns_total_stock_and_filters_by_category_tree(): void
    {
        $res = $this->actingAs($this->admin)->getJson('/api/inventory/items?with_stock=1&sort=code')->assertOk()->json('data.data');
        $rice = collect($res)->firstWhere('id', $this->rice->id);
        $this->assertSame('150.000000', $rice['total_quantity']);

        $tree = $this->actingAs($this->admin)->getJson("/api/inventory/items?category_id={$this->food->id}&category_tree=1")->json('data.data');
        $this->assertEqualsCanonicalizing([$this->rice->id, $this->oil->id], array_column($tree, 'id'));
        $flat = $this->actingAs($this->admin)->getJson("/api/inventory/items?category_id={$this->food->id}")->json('data.data');
        $this->assertSame([$this->oil->id], array_column($flat, 'id'));

        $balance = $this->actingAs($this->admin)->getJson("/api/inventory/balances?stock_item_id={$this->rice->id}")->json('data.data');
        $this->assertCount(2, $balance);
    }
}
