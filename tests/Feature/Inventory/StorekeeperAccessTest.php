<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\StockItem;
use App\Models\StockUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockMovementLine;
use Database\Seeders\StorekeeperSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * لوحة المخازن المستقلة: دور storekeeper (migration 2027_03_07_000001)، حصره بـ /api/inventory/*
 * عبر RestrictScopedRoles، الصلاحيات المفصّلة للأصناف، و GET /api/inventory/dashboard.
 */
class StorekeeperAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $keeper;

    private Warehouse $wh;

    private Warehouse $other;

    private StockUnit $gram;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $branch = Branch::factory()->create();
        $this->wh = Warehouse::create(['name' => 'مخزن أمين', 'code' => 'WH-K', 'branch_id' => $branch->id]);
        $this->other = Warehouse::create(['name' => 'مخزن آخر', 'code' => 'WH-O', 'branch_id' => $branch->id]);
        $this->gram = StockUnit::firstOrCreate(['name' => 'غرام'], ['is_active' => true]);

        $this->keeper = User::factory()->create();
        $this->keeper->assignRole('storekeeper');
        DB::table('warehouse_user')->insert(['warehouse_id' => $this->wh->id, 'user_id' => $this->keeper->id, 'access_level' => 'edit', 'created_at' => now()]);
    }

    private function item(string $code, string $safety = '0'): StockItem
    {
        return StockItem::create(['type' => 'raw', 'name' => "صنف {$code}", 'code' => $code, 'base_unit_id' => $this->gram->id, 'is_stored' => true, 'safety_stock' => $safety]);
    }

    private function stock(Warehouse $wh, StockItem $item, string $qty, string $cost): void
    {
        app(StockLedger::class)->post(new StockMovementLine($wh->id, $item->id, 'opening', $qty, unitCost: $cost));
    }

    public function test_storekeeper_role_has_only_inventory_quantity_permissions(): void
    {
        $perms = Role::findByName('storekeeper', 'web')->permissions->pluck('name')->sort()->values()->all();

        $this->assertSame([
            'inventory.access', 'inventory.analytics.view', 'inventory.counts.manage', 'inventory.counts.post',
            'inventory.documents.manage', 'inventory.documents.post', 'inventory.items.create', 'inventory.items.edit',
        ], $perms);
    }

    public function test_storekeeper_reaches_inventory_api_and_session_routes(): void
    {
        $this->actingAs($this->keeper)->getJson('/api/inventory/dashboard')->assertOk();
        $this->actingAs($this->keeper)->getJson('/api/inventory/items')->assertOk();
        $this->actingAs($this->keeper)->getJson('/api/auth/me')->assertOk()->assertJsonPath('roles', ['storekeeper']);
    }

    public function test_storekeeper_gets_403_outside_inventory(): void
    {
        foreach (['/api/departments', '/api/customers', '/api/admin/printers', '/api/users'] as $url) {
            $this->actingAs($this->keeper)->getJson($url)->assertForbidden();
        }
        $this->actingAs($this->keeper)->postJson('/api/departments', ['name' => 'x'])->assertForbidden();
    }

    public function test_storekeeper_gets_403_on_inventory_actions_without_permission(): void
    {
        $item = $this->item('K1');

        $this->actingAs($this->keeper)->deleteJson("/api/inventory/items/{$item->id}")->assertForbidden();
        $this->actingAs($this->keeper)->putJson('/api/inventory/settings', ['allow_negative_stock' => true])->assertForbidden();
        $this->actingAs($this->keeper)->postJson('/api/inventory/warehouses', ['name' => 'x'])->assertForbidden();
        $this->actingAs($this->keeper)->putJson("/api/inventory/items/{$item->id}/suppliers", ['suppliers' => []])->assertForbidden();
        $this->assertDatabaseHas('stock_items', ['id' => $item->id]);
    }

    public function test_storekeeper_can_create_items_with_granular_permission(): void
    {
        $this->actingAs($this->keeper)->postJson('/api/inventory/items', [
            'type' => 'raw', 'name' => 'طحين', 'code' => 'FLR', 'base_unit_id' => $this->gram->id,
        ])->assertCreated();
    }

    public function test_user_with_another_role_is_not_restricted(): void
    {
        Role::findOrCreate('cashier', 'web');
        $this->keeper->assignRole('cashier');

        $this->assertNotSame(403, $this->actingAs($this->keeper->fresh())->getJson('/api/departments')->status());
    }

    public function test_legacy_items_manage_holders_get_the_granular_permissions(): void
    {
        $role = Role::findOrCreate('branch-manager', 'web');
        $role->givePermissionTo(Permission::findOrCreate('inventory.items.manage', 'web'));
        Role::findOrCreate('super-admin', 'web');
        $migration = require database_path('migrations/2027_03_07_000001_add_storekeeper_role.php');
        $migration->up();

        $this->assertTrue($role->fresh()->hasPermissionTo('inventory.items.delete'));
        $this->assertTrue(Role::findByName('super-admin', 'web')->hasPermissionTo('inventory.items.create'));
    }

    public function test_dashboard_counts_are_scoped_to_assigned_warehouses_and_hide_value(): void
    {
        $low = $this->item('LOW', '1000');
        $ok = $this->item('OK', '100');
        $this->item('NONE', '50');               // لا رصيد إطلاقاً → تحت الأمان
        $this->item('NOSAFE');                   // بلا حد أمان → لا يُحسب
        $this->stock($this->wh, $low, '400', '2');
        $this->stock($this->wh, $ok, '500', '1');
        $this->stock($this->other, $low, '5000', '2'); // مخزن غير مربوط — لا يُحسب لأمين المخزن

        $data = $this->actingAs($this->keeper)->getJson('/api/inventory/dashboard')->assertOk()->json('data');

        $this->assertSame(4, $data['items_count']);
        $this->assertSame(1, $data['warehouses_count']);
        $this->assertSame(2, $data['below_safety_count']);
        $this->assertSame(['NONE', 'LOW'], array_column($data['below_safety'], 'code'));
        $this->assertCount(2, $data['recent_movements']);
        $this->assertNull($data['recent_movements'][0]['total_cost']);
        $this->assertNull($data['stock_value']);
    }

    public function test_dashboard_value_for_cost_viewer_with_all_warehouses(): void
    {
        $item = $this->item('V1');
        $this->stock($this->wh, $item, '400', '2');
        $this->stock($this->other, $item, '100', '3');
        $admin = User::factory()->create();
        $admin->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p, 'web'), ['inventory.access', 'inventory.costs.view', 'inventory.warehouses.all']));

        $data = $this->actingAs($admin)->getJson('/api/inventory/dashboard')->assertOk()->json('data');

        $this->assertSame('1100', $data['stock_value']);
        $this->assertSame(0, $data['below_safety_count']);
    }

    public function test_seeder_reads_password_from_env_and_refuses_without_it(): void
    {
        putenv('STOREKEEPER_SEED_PASSWORD');
        $this->artisan('db:seed', ['--class' => StorekeeperSeeder::class])->assertSuccessful();
        $this->assertDatabaseMissing('users', ['username' => 'store']);

        putenv('STOREKEEPER_SEED_PASSWORD=secret-from-env');
        try {
            $this->artisan('db:seed', ['--class' => StorekeeperSeeder::class])->assertSuccessful();
        } finally {
            putenv('STOREKEEPER_SEED_PASSWORD');
        }

        $user = User::firstWhere('username', 'store');
        $this->assertTrue($user->hasRole('storekeeper'));
        $this->postJson('/api/login', ['username' => 'store', 'password' => 'secret-from-env'])->assertOk()
            ->assertJsonPath('data.roles', ['storekeeper']);
        $this->assertDatabaseHas('warehouse_user', ['user_id' => $user->id, 'warehouse_id' => $this->other->id, 'access_level' => 'edit']);
    }
}
