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

    public function test_item_stock_returns_balance_and_units_and_hides_cost_without_permission(): void
    {
        $this->seedStock($this->store, '2500', '0.04');
        $url = "/api/inventory/items/{$this->meat->id}/stock?warehouse_id={$this->store->id}";

        $res = $this->actingAs($this->admin)->getJson($url)->assertOk();
        $this->assertSame('2500.000000', $res->json('data.qty'));
        $this->assertSame('0.040000', $res->json('data.avg_cost'));
        $this->assertSame('غرام', $res->json('data.unit'));
        $this->assertSame(['1', '1000.000000'], array_column($res->json('data.units'), 'factor'));

        $keeper = $this->user(['inventory.access']);
        $this->actingAs($keeper)->getJson($url)->assertForbidden();
        $this->store->users()->attach($keeper->id, ['created_at' => now()]);
        $this->assertNull($this->actingAs($keeper)->getJson($url)->assertOk()->json('data.avg_cost'));
    }

    public function test_transfer_beyond_source_balance_is_rejected_when_negative_stock_is_off(): void
    {
        InventorySetting::current()->update(['allow_negative_stock' => false]);
        $this->seedStock($this->store, '1000', '0.04');

        // 2 كغ = 2000 غ والرصيد 1000 غ فقط
        $id = $this->transfer([['stock_item_id' => $this->meat->id, 'quantity' => 2, 'unit_id' => $this->unit('كيلوغرام')->id]])
            ->assertCreated()->json('data.id');   // المسودة مسموحة
        $this->postDoc($id)->assertStatus(422);

        $this->assertSame('draft', StockDocument::find($id)->status);
        $this->assertSame('1000.000000', StockBalance::where('warehouse_id', $this->store->id)->value('quantity'));
        $this->assertSame(0, StockMovement::where('source_type', 'stock_document')->count());
    }

    public function test_transfer_beyond_source_balance_posts_and_goes_negative_when_allowed(): void
    {
        InventorySetting::current()->update(['allow_negative_stock' => true]);
        $this->seedStock($this->store, '1000', '0.04');

        // 2 كغ = 2000 غ والرصيد 1000 غ فقط ← يُرحَّل، المصدر −1000 غ، والوجهة تستلم 2000 غ بالتكلفة نفسها
        $id = $this->transfer([['stock_item_id' => $this->meat->id, 'quantity' => 2, 'unit_id' => $this->unit('كيلوغرام')->id]])
            ->assertCreated()->json('data.id');
        $this->postDoc($id)->assertOk();

        $this->assertSame('posted', StockDocument::find($id)->status);
        $this->assertSame('-1000.000000', StockBalance::where('warehouse_id', $this->store->id)->value('quantity'));
        $this->assertSame('2000.000000', StockBalance::where('warehouse_id', $this->kitchen->id)->value('quantity'));
        $this->assertSame('0.040000', StockBalance::where('warehouse_id', $this->kitchen->id)->value('avg_cost'));
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

    // ── رقم المرجع ────────────────────────────────────────────────────────────

    public function test_reference_number_is_saved_and_searchable(): void
    {
        $id = $this->transfer([['stock_item_id' => $this->meat->id, 'quantity' => 1]], ['reference_no' => 'REQ-SHW-0412'])
            ->assertCreated()->assertJsonPath('data.reference_no', 'REQ-SHW-0412')->json('data.id');
        $this->transfer([['stock_item_id' => $this->meat->id, 'quantity' => 1]])->assertCreated();

        $found = $this->actingAs($this->admin)->getJson('/api/inventory/documents?type=transfer&search=SHW-04')->assertOk()->json('data.data');
        $this->assertSame([$id], array_column($found, 'id'));
        $this->assertSame('REQ-SHW-0412', $found[0]['reference_no']);
    }

    // ── التكرار ───────────────────────────────────────────────────────────────

    public function test_duplicate_creates_a_dated_today_draft_without_receipt(): void
    {
        $this->seedStock($this->store, '5000', '0.04');
        $kg = $this->unit('كيلوغرام')->id;
        $id = $this->transfer([
            ['stock_item_id' => $this->meat->id, 'quantity' => 1.5, 'unit_id' => $kg, 'notes' => 'للشاورما'],
        ], ['destination' => 'قسم الشاورما', 'notes' => 'صرف صباحي', 'document_date' => now()->subDays(3)->toDateString()])->json('data.id');

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

    // ── صرفيات الأصناف: مخزن وجهة لكل سطر ─────────────────────────────────────

    private function balance(Warehouse $wh): ?StockBalance
    {
        return StockBalance::where('warehouse_id', $wh->id)->where('stock_item_id', $this->meat->id)->first();
    }

    public function test_lines_with_two_destinations_move_stock_to_each_warehouse_at_the_source_cost(): void
    {
        $bakery = $this->warehouse('قسم الكيك', 'WH-CAKE');
        $this->seedStock($this->store, '5000', '0.04');
        $id = $this->transfer([
            ['stock_item_id' => $this->meat->id, 'quantity' => 1200],
            ['stock_item_id' => $this->meat->id, 'quantity' => 800, 'to_warehouse_id' => $bakery->id],
        ])->assertCreated()->json('data.id');

        $doc = $this->postDoc($id)->assertOk()->json('data');
        $this->assertSame([$this->kitchen->id, $bakery->id], array_column($doc['destinations'], 'id'));
        $this->assertSame(2, $doc['destinations_count']);
        $this->assertSame('pending', $doc['receipt_status']);

        $this->assertSame('3000.000000', $this->balance($this->store)->quantity);
        $this->assertSame('1200.000000', $this->balance($this->kitchen)->quantity);
        $this->assertSame('800.000000', $this->balance($bakery)->quantity);
        // تكلفة الوجهة = متوسط تكلفة المصدر
        $this->assertSame('0.040000', $this->balance($this->kitchen)->avg_cost);
        $this->assertSame('0.040000', $this->balance($bakery)->avg_cost);
        $in = StockMovement::where('type', 'transfer_in')->where('source_id', $id)->pluck('unit_cost', 'warehouse_id');
        $out = StockMovement::where('type', 'transfer_out')->where('source_id', $id)->pluck('unit_cost')->unique()->values()->all();
        $this->assertSame(['0.040000'], $out);
        $this->assertSame('0.040000', $in[$bakery->id]);
        $this->assertSame('80.000000', StockDocument::find($id)->total_cost);
    }

    public function test_the_same_item_on_several_lines_cannot_exceed_the_source_balance_together(): void
    {
        $bakery = $this->warehouse('قسم الكيك', 'WH-CAKE');
        $this->seedStock($this->store, '1000', '0.04');
        $id = $this->transfer([
            ['stock_item_id' => $this->meat->id, 'quantity' => 700],
            ['stock_item_id' => $this->meat->id, 'quantity' => 400, 'to_warehouse_id' => $bakery->id],
        ])->json('data.id');

        $this->postDoc($id)->assertStatus(422);
        $this->assertSame('draft', StockDocument::find($id)->status);
        $this->assertSame('1000.000000', $this->balance($this->store)->quantity);
        $this->assertNull($this->balance($bakery));
    }

    public function test_line_destination_cannot_be_the_source_warehouse(): void
    {
        $this->transfer([['stock_item_id' => $this->meat->id, 'quantity' => 1, 'to_warehouse_id' => $this->store->id]])
            ->assertStatus(422);
    }

    public function test_reversing_a_multi_destination_transfer_restores_every_balance(): void
    {
        $bakery = $this->warehouse('قسم الكيك', 'WH-CAKE');
        $this->seedStock($this->store, '5000', '0.04');
        $id = $this->transfer([
            ['stock_item_id' => $this->meat->id, 'quantity' => 1000],
            ['stock_item_id' => $this->meat->id, 'quantity' => 500, 'to_warehouse_id' => $bakery->id],
        ])->json('data.id');
        $this->postDoc($id)->assertOk();

        $this->actingAs($this->admin)->postJson("/api/inventory/documents/{$id}/reverse", ['reason' => 'خطأ'])->assertOk();
        $this->assertSame('5000.000000', $this->balance($this->store)->quantity);
        $this->assertSame('0.000000', $this->balance($this->kitchen)->quantity);
        $this->assertSame('0.000000', $this->balance($bakery)->quantity);
    }

    public function test_receipt_is_confirmed_per_destination_and_completes_when_all_are_received(): void
    {
        $bakery = $this->warehouse('قسم الكيك', 'WH-CAKE');
        $this->seedStock($this->store, '5000', '0.04');
        $id = $this->transfer([
            ['stock_item_id' => $this->meat->id, 'quantity' => 1000],
            ['stock_item_id' => $this->meat->id, 'quantity' => 500, 'to_warehouse_id' => $bakery->id],
        ])->json('data.id');
        $this->postDoc($id)->assertOk();

        $kitchenKeeper = $this->user(['inventory.access', 'inventory.documents.manage']);
        $this->kitchen->users()->attach($kitchenKeeper->id, ['created_at' => now()]);
        $bakeryKeeper = $this->user(['inventory.access', 'inventory.documents.manage']);
        $bakery->users()->attach($bakeryKeeper->id, ['created_at' => now()]);

        // كل أمين يرى الصرفية من وجهته ويستلم وجهته فقط
        $seen = $this->actingAs($bakeryKeeper)->getJson("/api/inventory/documents/{$id}")->assertOk()->json('data');
        $this->assertSame([$bakery->id], $seen['receivable_warehouse_ids']);

        $partial = $this->receive($id, $kitchenKeeper)->assertOk()->json('data');
        $this->assertSame('partial', $partial['receipt_status']);
        $this->assertNull($partial['received_at']);
        $this->assertNotNull(collect($partial['destinations'])->firstWhere('id', $this->kitchen->id)['received_at']);
        $this->assertNull(collect($partial['destinations'])->firstWhere('id', $bakery->id)['received_at']);
        $this->receive($id, $kitchenKeeper)->assertForbidden();

        $list = $this->actingAs($this->admin)->getJson('/api/inventory/documents?type=transfer&receipt=partial')->assertOk();
        $this->assertSame([$id], array_column($list->json('data.data'), 'id'));

        $done = $this->receive($id, $bakeryKeeper)->assertOk()->json('data');
        $this->assertSame('received', $done['receipt_status']);
        $this->assertNotNull($done['received_at']);
        $this->assertSame('delivered', $done['delivery_status']);

        // المستلَم جزئياً أو كلياً يحتاج صلاحية عكس المستلَم
        $this->actingAs($this->admin)->postJson("/api/inventory/documents/{$id}/reverse", ['reason' => 'x'])->assertForbidden();
    }

    public function test_quantities_only_time_and_line_destination_are_saved_and_duplicated(): void
    {
        $bakery = $this->warehouse('قسم الكيك', 'WH-CAKE');
        $id = $this->transfer([
            ['stock_item_id' => $this->meat->id, 'quantity' => 2, 'to_warehouse_id' => $bakery->id],
        ], ['quantities_only' => true, 'document_time' => '07:45'])->assertCreated()->json('data');
        $this->assertTrue($id['quantities_only']);
        $this->assertSame('07:45:00', $id['document_time']);
        $this->assertSame($bakery->id, $id['lines'][0]['to_warehouse_id']);
        $this->assertSame('WH-CAKE', $id['lines'][0]['to_warehouse']['code']);

        $copy = $this->actingAs($this->admin)->postJson("/api/inventory/documents/{$id['id']}/duplicate")->assertCreated()->json('data');
        $this->assertTrue($copy['quantities_only']);
        $this->assertSame($bakery->id, $copy['lines'][0]['to_warehouse_id']);
        $this->assertNotNull($copy['document_time']);
    }

    public function test_navigate_and_open_by_number_stay_within_the_type_and_visible_warehouses(): void
    {
        $a = $this->transfer([['stock_item_id' => $this->meat->id, 'quantity' => 1]])->json('data');
        $b = $this->transfer([['stock_item_id' => $this->meat->id, 'quantity' => 1]])->json('data');
        $this->actingAs($this->admin)->postJson('/api/inventory/documents', [
            'type' => 'waste', 'warehouse_id' => $this->store->id,
            'lines' => [['stock_item_id' => $this->meat->id, 'quantity' => 1, 'waste_reason' => 'damaged']],
        ])->assertCreated();

        $nav = fn (array $q) => $this->actingAs($this->admin)->getJson('/api/inventory/documents/navigate?'.http_build_query(['type' => 'transfer', ...$q]))->assertOk()->json('data');
        $this->assertSame($a['id'], $nav(['dir' => 'first'])['id']);
        $this->assertSame($b['id'], $nav(['dir' => 'last'])['id']);
        $this->assertSame($b['id'], $nav(['dir' => 'next', 'id' => $a['id']])['id']);
        $this->assertNull($nav(['dir' => 'next', 'id' => $b['id']]));
        $this->assertSame($a['id'], $nav(['number' => strtolower($a['number'])])['id']);
        $this->assertSame($b['id'], $nav(['number' => (string) $b['id']])['id']);

        $stranger = $this->user(['inventory.access']);
        $this->actingAs($stranger)->getJson('/api/inventory/documents/navigate?type=transfer&dir=last')->assertOk()->assertJsonPath('data', null);
    }

    public function test_list_summary_counts_documents_and_totals_posted_value_and_meta_gives_the_accounting_day(): void
    {
        $this->seedStock($this->store, '5000', '0.04');
        $posted = $this->transfer([['stock_item_id' => $this->meat->id, 'quantity' => 1000]])->json('data.id');
        $this->postDoc($posted)->assertOk();
        $this->transfer([['stock_item_id' => $this->meat->id, 'quantity' => 500]]);

        $res = $this->actingAs($this->admin)->getJson('/api/inventory/documents?type=transfer&order=asc')->assertOk();
        $this->assertSame(2, $res->json('data.summary.count'));
        $this->assertSame('40.000000', $res->json('data.summary.posted_total'));
        $this->assertSame($posted, $res->json('data.data.0.id'));

        $meta = $this->actingAs($this->admin)->getJson('/api/inventory/documents/meta')->assertOk();
        $this->assertSame(\App\Services\Inventory\AccountingDay::fromConfig()->of(now()), $meta->json('data.accounting_today'));
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $meta->json('data.now_time'));
    }
}
