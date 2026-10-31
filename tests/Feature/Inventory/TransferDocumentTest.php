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

    // ── تأكيد الاستلام ────────────────────────────────────────────────────────

    private function postedTransfer(): int
    {
        $this->seedStock($this->store, '5000', '0.04');
        $id = $this->transfer([['stock_item_id' => $this->meat->id, 'quantity' => 1000]], ['delivery_status' => 'delivered'])->json('data.id');
        $this->postDoc($id)->assertOk();

        return $id;
    }

    private function receive(int $id, User $as): TestResponse
    {
        return $this->actingAs($as)->patchJson("/api/inventory/documents/{$id}/delivery", ['delivery_status' => 'delivered']);
    }

    public function test_new_transfer_starts_unreceived_and_draft_cannot_be_received(): void
    {
        $id = $this->transfer([['stock_item_id' => $this->meat->id, 'quantity' => 1]], ['delivery_status' => 'delivered'])
            ->assertCreated()->assertJsonPath('data.delivery_status', 'undelivered')->assertJsonPath('data.received_at', null)->json('data.id');

        $this->receive($id, $this->admin)->assertStatus(422);
    }

    public function test_receipt_confirmation_requires_edit_access_on_the_destination_warehouse(): void
    {
        $id = $this->postedTransfer();
        $perms = ['inventory.access', 'inventory.documents.manage'];

        $sourceKeeper = $this->user($perms);
        $this->store->users()->attach($sourceKeeper->id, ['created_at' => now()]);
        $viewer = $this->user($perms);
        $this->kitchen->users()->attach($viewer->id, ['created_at' => now(), 'access_level' => 'view']);
        $deptKeeper = $this->user($perms);
        $this->kitchen->users()->attach($deptKeeper->id, ['created_at' => now(), 'access_level' => 'edit']);

        $this->receive($id, $sourceKeeper)->assertForbidden();
        $this->receive($id, $viewer)->assertForbidden();
        $this->assertNull(StockDocument::find($id)->received_at);
        $this->assertFalse($this->actingAs($viewer)->getJson("/api/inventory/documents/{$id}")->json('data.can_receive'));
        $this->assertTrue($this->actingAs($deptKeeper)->getJson("/api/inventory/documents/{$id}")->json('data.can_receive'));

        $balanceBefore = StockBalance::where('warehouse_id', $this->kitchen->id)->value('quantity');
        $this->receive($id, $deptKeeper)->assertOk()
            ->assertJsonPath('data.received_by', $deptKeeper->id)
            ->assertJsonPath('data.receiver.name', $deptKeeper->name)
            ->assertJsonPath('data.delivery_status', 'delivered')
            ->assertJsonPath('data.can_receive', false);
        $this->assertNotNull(StockDocument::find($id)->received_at);
        // التأكيد توثيق فقط: لا حركة ولا تغيير رصيد
        $this->assertSame($balanceBefore, StockBalance::where('warehouse_id', $this->kitchen->id)->value('quantity'));
        $this->assertSame(2, StockMovement::where('source_type', 'stock_document')->count());

        $this->receive($id, $deptKeeper)->assertStatus(422);
        $this->actingAs($this->admin)->patchJson("/api/inventory/documents/{$id}/delivery", ['delivery_status' => 'undelivered'])->assertStatus(422);

        $ids = array_column($this->actingAs($this->admin)->getJson('/api/inventory/documents?type=transfer&received=1')->json('data.data'), 'id');
        $this->assertSame([$id], $ids);
        $this->assertSame([], $this->actingAs($this->admin)->getJson('/api/inventory/documents?type=transfer&received=0')->json('data.data'));
    }

    public function test_received_transfer_can_be_reversed_only_with_reverse_received_permission(): void
    {
        $id = $this->postedTransfer();
        $this->receive($id, $this->admin)->assertOk();
        $this->assertFalse($this->actingAs($this->admin)->getJson("/api/inventory/documents/{$id}")->json('data.can_reverse'));

        $this->actingAs($this->admin)->postJson("/api/inventory/documents/{$id}/reverse", ['reason' => 'خطأ'])->assertForbidden();
        $this->assertSame('posted', StockDocument::find($id)->status);

        $manager = $this->user([...self::ADMIN, 'inventory.documents.reverse_received']);
        $this->assertTrue($this->actingAs($manager)->getJson("/api/inventory/documents/{$id}")->json('data.can_reverse'));
        $this->actingAs($manager)->postJson("/api/inventory/documents/{$id}/reverse", ['reason' => 'خطأ'])->assertOk()
            ->assertJsonPath('data.status', 'reversed');
    }

    // ── التكرار ───────────────────────────────────────────────────────────────

    public function test_duplicate_creates_a_dated_today_draft_without_batches_or_receipt(): void
    {
        $this->seedStock($this->store, '5000', '0.04');
        $batch = \App\Models\StockBatch::create([
            'stock_item_id' => $this->meat->id, 'source' => 'receipt', 'sequence' => 1, 'code' => 'RM-MEAT1',
            'quantity' => 0, 'expiry_date' => now()->addMonth()->toDateString(),
        ]);
        $kg = $this->unit('كيلوغرام')->id;
        $id = $this->transfer([
            ['stock_item_id' => $this->meat->id, 'quantity' => 1.5, 'unit_id' => $kg, 'notes' => 'للشاورما'],
        ], ['destination' => 'قسم الشاورما', 'notes' => 'صرف صباحي', 'document_date' => now()->subDays(3)->toDateString()])->json('data.id');
        StockDocument::find($id)->lines()->update(['batch_id' => $batch->id]);

        $copy = $this->actingAs($this->admin)->postJson("/api/inventory/documents/{$id}/duplicate")->assertCreated()->json('data');

        $this->assertNotSame($id, $copy['id']);
        $this->assertSame('draft', $copy['status']);
        $this->assertSame($this->store->id, $copy['warehouse_id']);
        $this->assertSame($this->kitchen->id, $copy['to_warehouse_id']);
        $this->assertSame('قسم الشاورما', $copy['destination']);
        $this->assertSame('صرف صباحي', $copy['notes']);
        $this->assertNotSame(StockDocument::find($id)->document_date->toDateString(), $copy['document_date']);
        $this->assertNull($copy['received_at']);
        $this->assertSame('undelivered', $copy['delivery_status']);
        $this->assertCount(1, $copy['lines']);
        $this->assertSame('1.500000', $copy['lines'][0]['quantity']);
        $this->assertSame($kg, $copy['lines'][0]['unit_id']);
        $this->assertSame('للشاورما', $copy['lines'][0]['notes']);
        $this->assertNull($copy['lines'][0]['batch_id']);

        // غير الصرف لا يُكرَّر، ولا يُكرّر من لا يصل لمخزن المصدر
        $waste = $this->actingAs($this->admin)->postJson('/api/inventory/documents', [
            'type' => 'waste', 'warehouse_id' => $this->store->id,
            'lines' => [['stock_item_id' => $this->meat->id, 'quantity' => 1, 'waste_reason' => 'expired']],
        ])->json('data.id');
        $this->actingAs($this->admin)->postJson("/api/inventory/documents/{$waste}/duplicate")->assertStatus(422);
        $outsider = $this->user(['inventory.access', 'inventory.documents.manage']);
        $this->kitchen->users()->attach($outsider->id, ['created_at' => now()]);
        $this->actingAs($outsider)->postJson("/api/inventory/documents/{$id}/duplicate")->assertForbidden();
    }
}
