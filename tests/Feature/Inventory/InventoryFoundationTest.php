<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Item;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryUsage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** المرحلة 1: الأصناف والتصنيفات والوحدات والمخازن والصلاحيات وسجل التدقيق. */
class InventoryFoundationTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = [
        'inventory.access',
        'inventory.items.manage',
        'inventory.catalog.manage',
        'inventory.warehouses.manage',
    ];

    private function user(array $permissions, ?Branch $branch = null): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user = User::factory()->create(['branch_id' => $branch?->id]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function unit(string $name): StockUnit
    {
        return StockUnit::where('name', $name)->firstOrFail();
    }

    private function rawItem(User $by, string $name = 'لحمة'): StockItem
    {
        $res = $this->actingAs($by)->postJson('/api/inventory/items', [
            'type' => 'raw',
            'name' => $name,
            'base_unit_id' => $this->unit('غرام')->id,
        ])->assertCreated();

        return StockItem::findOrFail($res->json('data.id'));
    }

    // ── الأصناف ────────────────────────────────────────────────────────────────

    public function test_raw_item_with_alternative_units_and_categories(): void
    {
        $admin = $this->user(self::ALL);
        $meat = StockCategory::create(['name' => 'لحوم']);
        $frozen = StockCategory::create(['name' => 'مجمدات']);

        $res = $this->actingAs($admin)->postJson('/api/inventory/items', [
            'type' => 'raw',
            'name' => 'لحمة برغر',
            'base_unit_id' => $this->unit('غرام')->id,
            'barcode' => '6291000000011',
            'supplier_code' => 'SUP-77',
            'safety_stock' => 5000,
            'tracks_expiry' => true,
            'is_stored' => false, // يُتجاهل: المادة الخام تُخزَّن دائماً
            'units' => [
                ['unit_id' => $this->unit('كيلوغرام')->id, 'factor' => 1000],
                ['unit_id' => $this->unit('كرتونة')->id, 'factor' => 10000],
            ],
            'category_ids' => [$meat->id, $frozen->id],
        ])->assertCreated();

        $item = StockItem::with(['units', 'categories'])->find($res->json('data.id'));
        $this->assertSame('RM-0001', $item->code);
        $this->assertTrue($item->is_stored);
        $this->assertCount(2, $item->units);
        $this->assertEqualsCanonicalizing([$meat->id, $frozen->id], $item->categories->pluck('id')->all());
        $this->assertSame('1000.000000', (string) $item->units->firstWhere('unit_id', $this->unit('كيلوغرام')->id)->factor);
    }

    public function test_finished_item_must_link_to_o2_sale_item_and_takes_its_name(): void
    {
        $admin = $this->user(self::ALL);
        $burger = Item::factory()->create(['name' => 'Burger', 'name_ar' => 'برغر']);

        $this->actingAs($admin)->postJson('/api/inventory/items', [
            'type' => 'finished',
            'base_unit_id' => $this->unit('حبة')->id,
        ])->assertStatus(422)->assertJsonPath('message', 'يجب ربط المنتج النهائي أو الصنف غير الخاضع للمخزون بصنف من قائمة البيع.');

        $this->actingAs($admin)->postJson('/api/inventory/items', [
            'type' => 'finished',
            'item_id' => $burger->id,
            'base_unit_id' => $this->unit('حبة')->id,
        ])->assertCreated()->assertJsonPath('data.name', 'برغر')->assertJsonPath('data.code', 'FP-0001');

        // صنف البيع نفسه لا يُربط بصنفين مخزنيين.
        $this->actingAs($admin)->postJson('/api/inventory/items', [
            'type' => 'finished',
            'item_id' => $burger->id,
            'base_unit_id' => $this->unit('حبة')->id,
        ])->assertStatus(422)->assertJsonPath('message', 'صنف البيع هذا مربوط بصنف مخزني آخر.');
    }

    public function test_raw_item_cannot_link_to_sale_item(): void
    {
        $admin = $this->user(self::ALL);
        $this->actingAs($admin)->postJson('/api/inventory/items', [
            'type' => 'raw',
            'name' => 'ثوم',
            'item_id' => Item::factory()->create()->id,
            'base_unit_id' => $this->unit('غرام')->id,
        ])->assertStatus(422);
    }

    public function test_non_stock_item_is_never_stored(): void
    {
        $admin = $this->user(self::ALL);
        $delivery = Item::factory()->create(['name_ar' => 'أجرة توصيل']);

        $this->actingAs($admin)->postJson('/api/inventory/items', [
            'type' => 'non_stock',
            'item_id' => $delivery->id,
            'base_unit_id' => $this->unit('حبة')->id,
            'is_stored' => true,
        ])->assertCreated()
            ->assertJsonPath('data.is_stored', false)
            ->assertJsonPath('data.code', 'NS-0001');
    }

    public function test_intermediate_item_keeps_stored_flag(): void
    {
        $admin = $this->user(self::ALL);
        $res = $this->actingAs($admin)->postJson('/api/inventory/items', [
            'type' => 'intermediate',
            'name' => 'صوص البرغر',
            'base_unit_id' => $this->unit('غرام')->id,
            'is_stored' => false,
        ])->assertCreated()->assertJsonPath('data.is_stored', false);

        $this->actingAs($admin)->putJson('/api/inventory/items/'.$res->json('data.id'), ['is_stored' => true])
            ->assertOk()->assertJsonPath('data.is_stored', true);
    }

    public function test_base_unit_cannot_repeat_in_alternative_units(): void
    {
        $admin = $this->user(self::ALL);
        $grams = $this->unit('غرام')->id;
        $this->actingAs($admin)->postJson('/api/inventory/items', [
            'type' => 'raw', 'name' => 'سكر', 'base_unit_id' => $grams,
            'units' => [['unit_id' => $grams, 'factor' => 1]],
        ])->assertStatus(422);
    }

    public function test_item_without_movements_can_be_deleted(): void
    {
        $admin = $this->user(self::ALL);
        $item = $this->rawItem($admin);

        $this->actingAs($admin)->deleteJson("/api/inventory/items/{$item->id}")->assertOk();
        $this->assertDatabaseMissing('stock_items', ['id' => $item->id]);
    }

    public function test_item_with_movements_cannot_be_deleted_only_deactivated(): void
    {
        $admin = $this->user(self::ALL);
        $item = $this->rawItem($admin);
        // جدول الحركات يأتي في المرحلة 2 — هنا نحاكي وجود حركات للصنف.
        $this->app->instance(InventoryUsage::class, new class extends InventoryUsage
        {
            public function itemHasMovements(int $stockItemId): bool
            {
                return true;
            }
        });

        $this->actingAs($admin)->deleteJson("/api/inventory/items/{$item->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن حذف صنف له حركات مخزنية. يمكنك إيقافه بدلاً من ذلك.');
        $this->assertDatabaseHas('stock_items', ['id' => $item->id]);

        $this->actingAs($admin)->putJson("/api/inventory/items/{$item->id}", [
            'base_unit_id' => $this->unit('كيلوغرام')->id,
        ])->assertStatus(422);

        $this->actingAs($admin)->putJson("/api/inventory/items/{$item->id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_validation_messages_are_arabic(): void
    {
        $admin = $this->user(self::ALL);
        $this->actingAs($admin)->postJson('/api/inventory/items', ['type' => 'raw'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'حقل الاسم مطلوب.')
            ->assertJsonPath('errors.base_unit_id.0', 'حقل الوحدة الأساسية مطلوب.');
    }

    // ── التصنيفات والوحدات ─────────────────────────────────────────────────────

    public function test_category_cycle_is_rejected(): void
    {
        $admin = $this->user(self::ALL);
        $a = StockCategory::create(['name' => 'A']);
        $b = StockCategory::create(['name' => 'B', 'parent_id' => $a->id]);

        $this->actingAs($admin)->putJson("/api/inventory/categories/{$a->id}", ['parent_id' => $b->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن جعل التصنيف تابعاً لنفسه أو لأحد فروعه.');
        $this->assertNull($a->fresh()->parent_id);
    }

    public function test_category_with_children_or_items_cannot_be_deleted(): void
    {
        $admin = $this->user(self::ALL);
        $parent = StockCategory::create(['name' => 'ألبان']);
        $child = StockCategory::create(['name' => 'أجبان', 'parent_id' => $parent->id]);

        $this->actingAs($admin)->deleteJson("/api/inventory/categories/{$parent->id}")->assertStatus(422);

        $item = $this->rawItem($admin, 'موزاريلا');
        $item->categories()->attach($child->id);
        $this->actingAs($admin)->deleteJson("/api/inventory/categories/{$child->id}")->assertStatus(422);
    }

    public function test_unit_in_use_cannot_be_deleted(): void
    {
        $admin = $this->user(self::ALL);
        $this->rawItem($admin);

        $this->actingAs($admin)->deleteJson('/api/inventory/units/'.$this->unit('غرام')->id)->assertStatus(422);
        $this->actingAs($admin)->deleteJson('/api/inventory/units/'.$this->unit('لتر')->id)->assertOk();
    }

    // ── المخازن ────────────────────────────────────────────────────────────────

    public function test_one_default_sales_warehouse_per_branch(): void
    {
        $admin = $this->user(self::ALL);
        $gaza = Branch::factory()->create();

        $first = $this->actingAs($admin)->postJson('/api/inventory/warehouses', [
            'name' => 'مخزن غزة الرئيسي', 'code' => 'WH-GZ-1', 'branch_id' => $gaza->id, 'is_default_sales' => true,
        ])->assertCreated()->json('data.id');
        $second = $this->actingAs($admin)->postJson('/api/inventory/warehouses', [
            'name' => 'مخزن غزة الجديد', 'code' => 'WH-GZ-2', 'branch_id' => $gaza->id, 'is_default_sales' => true,
        ])->assertCreated()->json('data.id');

        $this->assertFalse(Warehouse::find($first)->is_default_sales);
        $this->assertTrue(Warehouse::find($second)->is_default_sales);

        // القيد مفروض في قاعدة البيانات أيضاً، لا في الكود فقط.
        $this->expectException(QueryException::class);
        DB::table('warehouses')->where('id', $first)->update(['is_default_sales' => true]);
    }

    public function test_standard_warehouse_requires_branch_and_transit_is_unique(): void
    {
        $admin = $this->user(self::ALL);

        $this->actingAs($admin)->postJson('/api/inventory/warehouses', ['name' => 'بدون فرع', 'code' => 'WH-X'])
            ->assertStatus(422)->assertJsonPath('message', 'يجب اختيار الفرع الذي يتبعه المخزن.');

        $this->actingAs($admin)->postJson('/api/inventory/warehouses', [
            'name' => 'قيد النقل', 'code' => 'WH-TR', 'type' => 'transit', 'branch_id' => Branch::factory()->create()->id,
        ])->assertCreated()->assertJsonPath('data.branch_id', null);

        $this->actingAs($admin)->postJson('/api/inventory/warehouses', [
            'name' => 'قيد النقل 2', 'code' => 'WH-TR2', 'type' => 'transit',
        ])->assertStatus(422);
    }

    public function test_default_sales_warehouse_cannot_be_deactivated_or_deleted(): void
    {
        $admin = $this->user(self::ALL);
        $id = $this->actingAs($admin)->postJson('/api/inventory/warehouses', [
            'name' => 'مخزن', 'code' => 'WH-1', 'branch_id' => Branch::factory()->create()->id, 'is_default_sales' => true,
        ])->json('data.id');

        $this->actingAs($admin)->putJson("/api/inventory/warehouses/{$id}", ['is_active' => false])->assertStatus(422);
        $this->actingAs($admin)->deleteJson("/api/inventory/warehouses/{$id}")->assertStatus(422);
    }

    // ── الصلاحيات ──────────────────────────────────────────────────────────────

    public function test_nuseirat_keeper_cannot_access_gaza_warehouse(): void
    {
        $gaza = Branch::factory()->create(['name' => 'غزة']);
        $nuseirat = Branch::factory()->create(['name' => 'النصيرات']);
        $gazaWh = Warehouse::create(['name' => 'مخزن غزة', 'code' => 'GZ', 'branch_id' => $gaza->id]);
        $nusWh = Warehouse::create(['name' => 'مخزن النصيرات', 'code' => 'NS', 'branch_id' => $nuseirat->id]);

        $keeper = $this->user(['inventory.access', 'inventory.warehouses.manage'], $nuseirat);
        $nusWh->users()->attach($keeper->id, ['created_at' => now()]);

        $this->actingAs($keeper)->getJson('/api/inventory/warehouses')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $nusWh->id);
        $this->actingAs($keeper)->getJson("/api/inventory/warehouses/{$nusWh->id}")->assertOk();
        $this->actingAs($keeper)->getJson("/api/inventory/warehouses/{$gazaWh->id}")
            ->assertForbidden()->assertJsonPath('message', 'لا تملك صلاحية الوصول لهذا المخزن.');
        $this->actingAs($keeper)->putJson("/api/inventory/warehouses/{$gazaWh->id}", ['name' => 'x'])->assertForbidden();
        $this->actingAs($keeper)->putJson("/api/inventory/warehouses/{$gazaWh->id}/users", ['user_ids' => [$keeper->id]])->assertForbidden();
    }

    public function test_global_permission_sees_all_warehouses(): void
    {
        Warehouse::create(['name' => 'A', 'code' => 'A', 'branch_id' => Branch::factory()->create()->id]);
        Warehouse::create(['name' => 'B', 'code' => 'B', 'branch_id' => Branch::factory()->create()->id]);
        $auditor = $this->user(['inventory.access', 'inventory.warehouses.all']);

        $this->actingAs($auditor)->getJson('/api/inventory/warehouses')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_write_endpoints_require_their_permission(): void
    {
        $viewer = $this->user(['inventory.access']);
        $this->actingAs($viewer)->getJson('/api/inventory/items')->assertOk();
        $this->actingAs($viewer)->postJson('/api/inventory/items', [])->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/inventory/categories', ['name' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/inventory/warehouses', [])->assertForbidden();

        $outsider = $this->user([]);
        $this->actingAs($outsider)->getJson('/api/inventory/items')->assertForbidden();
    }

    public function test_creator_without_global_access_is_linked_to_new_warehouse(): void
    {
        $manager = $this->user(['inventory.access', 'inventory.warehouses.manage']);
        $id = $this->actingAs($manager)->postJson('/api/inventory/warehouses', [
            'name' => 'مخزن', 'code' => 'WH-M', 'branch_id' => Branch::factory()->create()->id,
        ])->assertCreated()->json('data.id');

        $this->actingAs($manager)->getJson("/api/inventory/warehouses/{$id}")->assertOk();
    }

    // ── سجل التدقيق ────────────────────────────────────────────────────────────

    public function test_create_update_delete_and_relations_are_audited(): void
    {
        $admin = $this->user([...self::ALL, 'inventory.warehouses.all']);
        $cat = StockCategory::create(['name' => 'خضار']);
        $item = $this->rawItem($admin, 'بندورة');

        $this->actingAs($admin)->putJson("/api/inventory/items/{$item->id}", [
            'name' => 'بندورة طازجة', 'category_ids' => [$cat->id],
        ])->assertOk();
        $this->actingAs($admin)->deleteJson("/api/inventory/items/{$item->id}")->assertOk();

        $events = AuditLog::where('auditable_type', StockItem::class)->where('auditable_id', $item->id)
            ->orderBy('id')->get();
        $this->assertSame(['created', 'updated', 'updated', 'updated', 'deleted'], $events->pluck('event')->all());
        $this->assertTrue($events->every(fn ($e) => $e->user_id === $admin->id));
        $this->assertSame(['category_ids' => [$cat->id]], $events[2]->new_values);
        $this->assertSame(['category_ids' => []], $events[3]->new_values);

        $warehouse = Warehouse::create(['name' => 'W', 'code' => 'W', 'branch_id' => Branch::factory()->create()->id]);
        $this->actingAs($admin)->putJson("/api/inventory/warehouses/{$warehouse->id}/users", ['user_ids' => [$admin->id]])->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Warehouse::class, 'auditable_id' => $warehouse->id, 'event' => 'updated',
        ]);
    }

    public function test_supplier_lead_time_column_exists(): void
    {
        $this->assertTrue(Schema::hasColumn('suppliers', 'lead_time_days'));
    }
}
