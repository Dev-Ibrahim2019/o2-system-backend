<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\StockBalance;
use App\Models\StockDocument;
use App\Models\StockItem;
use App\Models\StockItemUnit;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockMovementLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** المرحلة 3: مستندات الاستلام والتحويل والهالك (مسودة ← مرحّل ← معكوس) وموردو الأصناف. */
class StockDocumentTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = [
        'inventory.access', 'inventory.warehouses.all', 'inventory.costs.view',
        'inventory.documents.manage', 'inventory.documents.post', 'inventory.documents.reverse',
        'inventory.suppliers.manage', 'inventory.items.manage', 'inventory.receipts.direct',
    ];

    private User $admin;

    private Warehouse $store;

    private Warehouse $kitchen;

    private Supplier $supplier;

    private StockItem $meat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->user(self::ADMIN);
        $this->store = $this->warehouse('المخزن الجاف', 'WH-DRY');
        $this->kitchen = $this->warehouse('المطبخ', 'WH-KIT');
        $this->supplier = Supplier::create(['name' => 'ملاحم الأمين', 'code' => 'SUP-1']);
        // لحمة: الأساسية غرام، الكيلو 1000، والكرتونة 10 كغ = 10000 غرام، ووحدة الشراء الكرتونة.
        $this->meat = $this->item('لحمة', 'RM-MEAT', ['كيلوغرام' => 1000, 'كرتونة' => 10000], purchase: 'كرتونة');
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

    /** @param  array<string, int>  $alt */
    private function item(string $name, string $code, array $alt = [], ?string $purchase = null): StockItem
    {
        $item = StockItem::create([
            'type' => 'raw', 'name' => $name, 'code' => $code, 'base_unit_id' => $this->unit('غرام')->id, 'is_stored' => true,
        ]);
        foreach ($alt as $unit => $factor) {
            StockItemUnit::create(['stock_item_id' => $item->id, 'unit_id' => $this->unit($unit)->id, 'factor' => $factor]);
        }
        if ($purchase) {
            $item->update(['purchase_unit_id' => $this->unit($purchase)->id]);
        }

        return $item;
    }

    private function balance(StockItem $item, Warehouse $wh): ?StockBalance
    {
        return StockBalance::where('warehouse_id', $wh->id)->where('stock_item_id', $item->id)->first();
    }

    private function receipt(array $lines, array $extra = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)->postJson('/api/inventory/documents', [
            'type' => 'receipt', 'warehouse_id' => $this->store->id, 'supplier_id' => $this->supplier->id,
            'lines' => $lines, ...$extra,
        ]);
    }

    private function transfer(array $lines): TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/api/inventory/documents', [
            'type' => 'transfer', 'warehouse_id' => $this->store->id, 'to_warehouse_id' => $this->kitchen->id, 'lines' => $lines,
        ]);
    }

    private function postDoc(int $id, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)->postJson("/api/inventory/documents/{$id}/post");
    }

    /** رصيد ابتدائي مباشرة عبر المحرك. */
    private function seedStock(StockItem $item, Warehouse $wh, string $grams, string $costPerGram): void
    {
        app(StockLedger::class)->post(new StockMovementLine($wh->id, $item->id, 'opening', $grams, unitCost: $costPerGram));
    }

    // ── الاستلام ──────────────────────────────────────────────────────────────

    public function test_receipt_defaults_to_purchase_unit_and_posting_updates_balance_average_and_last_cost(): void
    {
        $this->seedStock($this->meat, $this->store, '10000', '0.04');   // 10 كغ @ 40 ₪/كغ

        // كرتونة واحدة (10 كغ) بسعر 500 ₪ للكرتونة = 50 ₪/كغ — بلا تحديد وحدة: وحدة الشراء.
        $doc = $this->receipt([['stock_item_id' => $this->meat->id, 'quantity' => 1, 'unit_cost' => 500]], ['supplier_invoice_no' => 'INV-77'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.lines.0.unit_id', $this->unit('كرتونة')->id)
            ->json('data');
        $this->assertStringStartsWith('GRN-', $doc['number']);
        $this->assertSame(0, StockMovement::where('source_type', 'stock_document')->count());   // المسودة لا تمس المخزون

        $res = $this->postDoc($doc['id'])->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertSame('500.000000', $res->json('data.total_cost'));
        $this->assertSame('500.000000', $res->json('data.lines.0.posted_cost'));

        $b = $this->balance($this->meat, $this->store);
        $this->assertSame('20000.000000', $b->quantity);
        $this->assertSame('0.045000', $b->avg_cost);   // (10×40 + 10×50) ÷ 20 = 45 ₪/كغ
        $this->assertSame('0.050000', $this->meat->fresh()->last_cost);

        $m = StockMovement::where('source_type', 'stock_document')->sole();
        $this->assertSame('purchase', $m->type);
        $this->assertSame('doc:'.$doc['id'].':line:'.$doc['lines'][0]['id'], $m->idempotency_key);
    }

    public function test_receipt_requires_supplier_and_rejects_duplicate_supplier_invoice(): void
    {
        $line = [['stock_item_id' => $this->meat->id, 'quantity' => 1, 'unit_cost' => 1]];
        $this->receipt($line, ['supplier_id' => null])->assertStatus(422);

        $first = $this->receipt($line, ['supplier_invoice_no' => 'INV-1'])->assertCreated()->json('data.number');
        $this->receipt($line, ['supplier_invoice_no' => 'INV-1'])
            ->assertStatus(422)
            ->assertJsonPath('message', "فاتورة المورّد هذه مسجّلة مسبقاً في المستند {$first}.");
    }

    public function test_posting_twice_does_not_double_the_stock(): void
    {
        $id = $this->receipt([['stock_item_id' => $this->meat->id, 'quantity' => 2, 'unit_cost' => 400]])->json('data.id');

        $this->postDoc($id)->assertOk();
        $this->postDoc($id)->assertOk()->assertJsonPath('data.status', 'posted');

        $this->assertSame(1, StockMovement::count());
        $this->assertSame('20000.000000', $this->balance($this->meat, $this->store)->quantity);
    }

    public function test_posted_document_cannot_be_edited_or_deleted(): void
    {
        $id = $this->receipt([['stock_item_id' => $this->meat->id, 'quantity' => 1, 'unit_cost' => 400]])->json('data.id');
        $this->postDoc($id)->assertOk();

        $this->actingAs($this->admin)->putJson("/api/inventory/documents/{$id}", [
            'warehouse_id' => $this->store->id, 'supplier_id' => $this->supplier->id,
            'lines' => [['stock_item_id' => $this->meat->id, 'quantity' => 5, 'unit_cost' => 1]],
        ])->assertStatus(422);
        $this->actingAs($this->admin)->deleteJson("/api/inventory/documents/{$id}")->assertStatus(422);

        $this->assertSame('1.000000', StockDocument::find($id)->lines()->value('quantity'));
    }

    public function test_draft_can_be_edited_and_deleted_without_touching_stock(): void
    {
        $id = $this->receipt([['stock_item_id' => $this->meat->id, 'quantity' => 1, 'unit_cost' => 400]])->json('data.id');

        $this->actingAs($this->admin)->putJson("/api/inventory/documents/{$id}", [
            'warehouse_id' => $this->store->id, 'supplier_id' => $this->supplier->id,
            'lines' => [['stock_item_id' => $this->meat->id, 'quantity' => 3, 'unit_id' => $this->unit('كيلوغرام')->id, 'unit_cost' => 45]],
        ])->assertOk()->assertJsonPath('data.lines.0.quantity', '3.000000');

        $this->actingAs($this->admin)->deleteJson("/api/inventory/documents/{$id}")->assertOk();
        $this->assertSame(0, StockDocument::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_reversing_a_receipt_restores_balance_and_average(): void
    {
        $this->seedStock($this->meat, $this->store, '10000', '0.04');
        $id = $this->receipt([['stock_item_id' => $this->meat->id, 'quantity' => 1, 'unit_cost' => 500]])->json('data.id');
        $this->postDoc($id)->assertOk();

        $this->actingAs($this->admin)->postJson("/api/inventory/documents/{$id}/reverse", ['reason' => 'فاتورة خاطئة'])
            ->assertOk()->assertJsonPath('data.status', 'reversed');

        $b = $this->balance($this->meat, $this->store);
        $this->assertSame('10000.000000', $b->quantity);
        $this->assertSame('0.040000', $b->avg_cost);

        $this->actingAs($this->admin)->postJson("/api/inventory/documents/{$id}/reverse", ['reason' => 'x'])
            ->assertStatus(422)->assertJsonPath('message', 'هذا المستند معكوس مسبقاً.');
    }

    public function test_receipt_cannot_be_reversed_after_its_stock_was_consumed(): void
    {
        $id = $this->receipt([['stock_item_id' => $this->meat->id, 'quantity' => 1, 'unit_cost' => 500]])->json('data.id');
        $this->postDoc($id)->assertOk();
        app(StockLedger::class)->post(new StockMovementLine($this->store->id, $this->meat->id, 'sale', '-6000'));

        $this->actingAs($this->admin)->postJson("/api/inventory/documents/{$id}/reverse", ['reason' => 'x'])->assertStatus(422);

        $this->assertSame('posted', StockDocument::find($id)->status);
        $this->assertSame('4000.000000', $this->balance($this->meat, $this->store)->quantity);
    }

    // ── التحويل ───────────────────────────────────────────────────────────────

    public function test_transfer_moves_quantity_and_value_at_source_average_cost(): void
    {
        $this->seedStock($this->meat, $this->store, '10000', '0.04');
        $this->seedStock($this->meat, $this->kitchen, '1000', '0.10');

        $id = $this->transfer([['stock_item_id' => $this->meat->id, 'quantity' => 3, 'unit_id' => $this->unit('كيلوغرام')->id]])
            ->assertCreated()->json('data.id');
        $this->postDoc($id)->assertOk()->assertJsonPath('data.total_cost', '120.000000');   // 3000 غ × 0.04

        $this->assertSame('7000.000000', $this->balance($this->meat, $this->store)->quantity);
        $kitchen = $this->balance($this->meat, $this->kitchen);
        $this->assertSame('4000.000000', $kitchen->quantity);
        // (1000 × 0.10 + 3000 × 0.04) ÷ 4000 = 0.055
        $this->assertSame('0.055000', $kitchen->avg_cost);

        $this->assertSame('0.040000', StockMovement::where('type', 'transfer_in')->sole()->unit_cost);
        // القيمة الإجمالية للمخزون لا تتغير بالتحويل: الصادر والوارد متساويان بإشارتين متعاكستين
        $this->assertEquals(0, (float) StockMovement::whereIn('type', ['transfer_in', 'transfer_out'])->sum('total_cost'));
    }

    public function test_transfer_with_insufficient_stock_writes_nothing(): void
    {
        $this->seedStock($this->meat, $this->store, '1000', '0.04');
        $id = $this->transfer([['stock_item_id' => $this->meat->id, 'quantity' => 5000]])->json('data.id');

        $this->postDoc($id)->assertStatus(422);

        $this->assertSame('draft', StockDocument::find($id)->status);
        $this->assertSame(1, StockMovement::count());
        $this->assertNull($this->balance($this->meat, $this->kitchen)?->last_movement_id);
    }

    public function test_transfer_to_same_warehouse_is_rejected(): void
    {
        $this->actingAs($this->admin)->postJson('/api/inventory/documents', [
            'type' => 'transfer', 'warehouse_id' => $this->store->id, 'to_warehouse_id' => $this->store->id,
            'lines' => [['stock_item_id' => $this->meat->id, 'quantity' => 1]],
        ])->assertStatus(422);
    }

    public function test_reversing_a_transfer_returns_stock_to_the_source(): void
    {
        $this->seedStock($this->meat, $this->store, '10000', '0.04');
        $id = $this->transfer([['stock_item_id' => $this->meat->id, 'quantity' => 2500]])->json('data.id');
        $this->postDoc($id)->assertOk();

        $this->actingAs($this->admin)->postJson("/api/inventory/documents/{$id}/reverse", ['reason' => 'خطأ'])->assertOk();

        $this->assertSame('10000.000000', $this->balance($this->meat, $this->store)->quantity);
        $this->assertSame('0.040000', $this->balance($this->meat, $this->store)->avg_cost);
        $this->assertSame('0.000000', $this->balance($this->meat, $this->kitchen)->quantity);
    }

    // ── الهالك ────────────────────────────────────────────────────────────────

    public function test_waste_requires_reason_and_posts_outgoing_at_average_cost(): void
    {
        $this->seedStock($this->meat, $this->store, '10000', '0.04');

        $this->actingAs($this->admin)->postJson('/api/inventory/documents', [
            'type' => 'waste', 'warehouse_id' => $this->store->id,
            'lines' => [['stock_item_id' => $this->meat->id, 'quantity' => 500]],
        ])->assertStatus(422);

        $id = $this->actingAs($this->admin)->postJson('/api/inventory/documents', [
            'type' => 'waste', 'warehouse_id' => $this->store->id,
            'lines' => [['stock_item_id' => $this->meat->id, 'quantity' => 500, 'waste_reason' => 'expired']],
        ])->assertCreated()->json('data.id');
        $this->postDoc($id)->assertOk()->assertJsonPath('data.total_cost', '20.000000');

        $m = StockMovement::where('type', 'waste')->sole();
        $this->assertSame('-500.000000', $m->quantity);
        $this->assertStringContainsString('منتهي الصلاحية', $m->notes);
        $this->assertSame('9500.000000', $this->balance($this->meat, $this->store)->quantity);
    }

    // ── الصلاحيات وإخفاء التكاليف ─────────────────────────────────────────────

    public function test_storekeeper_cannot_create_or_post_for_another_warehouse(): void
    {
        $keeper = $this->user(['inventory.access', 'inventory.documents.manage', 'inventory.documents.post']);
        $this->kitchen->users()->attach($keeper->id, ['created_at' => now()]);
        $id = $this->receipt([['stock_item_id' => $this->meat->id, 'quantity' => 1, 'unit_cost' => 1]])->json('data.id');

        $this->receipt([['stock_item_id' => $this->meat->id, 'quantity' => 1, 'unit_cost' => 1]], as: $keeper)->assertForbidden();
        $this->postDoc($id, $keeper)->assertForbidden();
        $this->actingAs($keeper)->getJson("/api/inventory/documents/{$id}")->assertForbidden();
        $this->assertSame([], $this->actingAs($keeper)->getJson('/api/inventory/documents')->json('data.data'));
    }

    public function test_posting_requires_post_permission(): void
    {
        $clerk = $this->user(['inventory.access', 'inventory.warehouses.all', 'inventory.documents.manage', 'inventory.receipts.direct']);
        $id = $this->receipt([['stock_item_id' => $this->meat->id, 'quantity' => 1, 'unit_cost' => 1]], as: $clerk)
            ->assertCreated()->json('data.id');

        $this->postDoc($id, $clerk)->assertForbidden();
        $this->assertSame('draft', StockDocument::find($id)->status);
    }

    public function test_costs_are_hidden_without_costs_permission(): void
    {
        $this->seedStock($this->meat, $this->store, '10000', '0.04');
        $viewer = $this->user(['inventory.access', 'inventory.warehouses.all']);

        $row = $this->actingAs($viewer)->getJson('/api/inventory/balances')->assertOk()->json('data.data.0');
        $this->assertSame('10000.000000', $row['quantity']);
        $this->assertNull($row['avg_cost']);
        $this->assertNull($row['value']);

        $kardex = $this->actingAs($viewer)
            ->getJson("/api/inventory/kardex?warehouse_id={$this->store->id}&stock_item_id={$this->meat->id}")
            ->assertOk();
        $this->assertFalse($kardex->json('data.costs_visible'));
        $this->assertNull($kardex->json('data.rows.0.unit_cost'));
        $this->assertSame('10000.000000', $kardex->json('data.rows.0.running_quantity'));

        $wasteId = $this->actingAs($this->admin)->postJson('/api/inventory/documents', [
            'type' => 'waste', 'warehouse_id' => $this->store->id,
            'lines' => [['stock_item_id' => $this->meat->id, 'quantity' => 100, 'waste_reason' => 'damaged']],
        ])->json('data.id');
        $this->postDoc($wasteId)->assertOk();
        $doc = $this->actingAs($viewer)->getJson("/api/inventory/documents/{$wasteId}")->assertOk();
        $this->assertNull($doc->json('data.total_cost'));
        $this->assertNull($doc->json('data.lines.0.posted_cost'));
    }

    // ── موردو الصنف ووحدة الشراء ──────────────────────────────────────────────

    public function test_item_suppliers_allow_only_one_preferred(): void
    {
        $other = Supplier::create(['name' => 'مزارع الخير', 'code' => 'SUP-2']);
        $url = "/api/inventory/items/{$this->meat->id}/suppliers";

        $this->actingAs($this->admin)->putJson($url, ['links' => [
            ['supplier_id' => $this->supplier->id, 'is_preferred' => true],
            ['supplier_id' => $other->id, 'is_preferred' => true],
        ]])->assertStatus(422);

        $this->actingAs($this->admin)->putJson($url, ['links' => [
            ['supplier_id' => $this->supplier->id, 'price' => 480, 'unit_id' => $this->unit('كرتونة')->id, 'is_preferred' => true],
            ['supplier_id' => $other->id, 'price' => 500],
        ]])->assertOk()->assertJsonPath('data.0.supplier.name', 'ملاحم الأمين')->assertJsonPath('data.0.is_preferred', true);

        // الاستبدال الكامل: حذف مورّد من القائمة يحذف ربطه
        $this->actingAs($this->admin)->putJson($url, ['links' => [['supplier_id' => $other->id]]])->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_purchase_unit_must_be_defined_for_the_item(): void
    {
        $this->actingAs($this->admin)->putJson("/api/inventory/items/{$this->meat->id}", [
            'purchase_unit_id' => $this->unit('لتر')->id,
        ])->assertStatus(422)->assertJsonPath('message', 'وحدة الشراء يجب أن تكون الوحدة الأساسية أو إحدى الوحدات البديلة.');

        $this->actingAs($this->admin)->putJson("/api/inventory/items/{$this->meat->id}", [
            'purchase_unit_id' => $this->unit('كيلوغرام')->id, 'name_en' => 'Beef',
        ])->assertOk()->assertJsonPath('data.name_en', 'Beef');
    }
}
