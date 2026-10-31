<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\InventorySetting;
use App\Models\StockBalance;
use App\Models\StockDocument;
use App\Models\StockItem;
use App\Models\StockItemUnit;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockMovementLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** شاشة "صرف للأقسام / تحويل": رصيد السطر، منع تجاوز الرصيد. */
class TransferDocumentTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = [
        'inventory.access', 'inventory.warehouses.all', 'inventory.costs.view',
        'inventory.documents.manage', 'inventory.documents.post', 'inventory.documents.reverse',
    ];

    private User $admin;

    private Warehouse $store;

    private Warehouse $kitchen;

    private StockItem $meat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->user(self::ADMIN);
        $this->store = $this->warehouse('المخزن الرئيسي', 'WH-MAIN');
        $this->kitchen = $this->warehouse('قسم الشاورما', 'WH-SHW');
        $this->meat = StockItem::create([
            'type' => 'raw', 'name' => 'لحمة', 'code' => 'RM-MEAT', 'base_unit_id' => $this->unit('غرام')->id, 'is_stored' => true,
        ]);
        StockItemUnit::create(['stock_item_id' => $this->meat->id, 'unit_id' => $this->unit('كيلوغرام')->id, 'factor' => 1000]);
    }

    // ── أدوات ─────────────────────────────────────────────────────────────────

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

    private function unit(string $name): StockUnit
    {
        return StockUnit::firstOrCreate(['name' => $name], ['is_active' => true]);
    }

    private function warehouse(string $name, string $code): Warehouse
    {
        return Warehouse::create(['name' => $name, 'code' => $code, 'branch_id' => Branch::factory()->create()->id]);
    }

    private function seedStock(Warehouse $wh, string $grams, string $costPerGram): void
    {
        app(StockLedger::class)->post(new StockMovementLine($wh->id, $this->meat->id, 'opening', $grams, unitCost: $costPerGram));
    }

    private function transfer(array $lines, array $extra = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)->postJson('/api/inventory/documents', [
            'type' => 'transfer', 'warehouse_id' => $this->store->id, 'to_warehouse_id' => $this->kitchen->id, 'lines' => $lines, ...$extra,
        ]);
    }

    private function postDoc(int $id, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)->postJson("/api/inventory/documents/{$id}/post");
    }

    // ── رصيد السطر وتجاوز الرصيد ─────────────────────────────────────────────

    public function test_item_stock_returns_balance_units_and_batches_and_hides_cost_without_permission(): void
    {
        $this->seedStock($this->store, '2500', '0.04');
        $url = "/api/inventory/items/{$this->meat->id}/stock?warehouse_id={$this->store->id}";

        $res = $this->actingAs($this->admin)->getJson($url)->assertOk();
        $this->assertSame('2500.000000', $res->json('data.qty'));
        $this->assertSame('0.040000', $res->json('data.avg_cost'));
        $this->assertSame('غرام', $res->json('data.unit'));
        $this->assertSame(['1', '1000.000000'], array_column($res->json('data.units'), 'factor'));
        $this->assertSame([], $res->json('data.batches'));

        $keeper = $this->user(['inventory.access']);
        $this->actingAs($keeper)->getJson($url)->assertForbidden();
        $this->store->users()->attach($keeper->id, ['created_at' => now()]);
        $this->assertNull($this->actingAs($keeper)->getJson($url)->assertOk()->json('data.avg_cost'));
    }

    public function test_transfer_never_exceeds_source_balance_even_when_negative_stock_is_allowed(): void
    {
        InventorySetting::current()->update(['allow_negative_stock' => true]);
        $this->seedStock($this->store, '1000', '0.04');

        // 2 كغ = 2000 غ والرصيد 1000 غ فقط
        $id = $this->transfer([['stock_item_id' => $this->meat->id, 'quantity' => 2, 'unit_id' => $this->unit('كيلوغرام')->id]])
            ->assertCreated()->json('data.id');   // المسودة مسموحة
        $this->postDoc($id)->assertStatus(422);

        $this->assertSame('draft', StockDocument::find($id)->status);
        $this->assertSame('1000.000000', StockBalance::where('warehouse_id', $this->store->id)->value('quantity'));
        $this->assertSame(0, StockMovement::where('source_type', 'stock_document')->count());
    }
}
