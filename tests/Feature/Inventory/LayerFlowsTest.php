<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\InventorySetting;
use App\Models\StockItem;
use App\Models\StockItemUnit;
use App\Models\StockLayer;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockCountService;
use App\Services\Inventory\StockDocumentService;
use App\Services\Inventory\StockLayerReportService;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockMovementLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** الدفعات عبر التحويل (اختبار المواصفة 5) والدفعة المؤقتة والجرد والتقارير. الكميات بالغرام. */
class LayerFlowsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Warehouse $store;

    private Warehouse $kitchen;

    private StockItem $meat;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $perms = ['inventory.access', 'inventory.warehouses.all', 'inventory.costs.view'];
        foreach ($perms as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo($perms);
        $branch = Branch::factory()->create();
        $this->store = Warehouse::create(['name' => 'المخزن الرئيسي', 'code' => 'WH-S', 'branch_id' => $branch->id]);
        $this->kitchen = Warehouse::create(['name' => 'مطبخ', 'code' => 'WH-K', 'branch_id' => $branch->id]);
        $gram = StockUnit::firstOrCreate(['name' => 'غرام'], ['is_active' => true]);
        $this->meat = StockItem::create(['type' => 'raw', 'name' => 'لحمة', 'code' => 'RM-F', 'base_unit_id' => $gram->id, 'is_stored' => true]);
        StockItemUnit::create(['stock_item_id' => $this->meat->id, 'unit_id' => StockUnit::firstOrCreate(['name' => 'كيلوغرام'], ['is_active' => true])->id, 'factor' => 1000]);
    }

    private function receive(Warehouse $w, string $qty, string $cost, array $layer = []): StockMovement
    {
        return app(StockLedger::class)->post(new StockMovementLine($w->id, $this->meat->id, 'purchase', $qty, unitCost: $cost, layer: $layer ?: null));
    }

    private function transfer(string $qty): void
    {
        $docs = app(StockDocumentService::class);
        $doc = $docs->create(['type' => 'transfer', 'warehouse_id' => $this->store->id, 'to_warehouse_id' => $this->kitchen->id,
            'lines' => [['stock_item_id' => $this->meat->id, 'quantity' => $qty]]], $this->admin);
        $docs->post($doc, $this->admin);
    }

    private function layers(Warehouse $w)
    {
        return StockLayer::where('warehouse_id', $w->id)->where('stock_item_id', $this->meat->id)->orderBy('id')->get();
    }

    public function test_5_transfer_keeps_price_receipt_date_and_original_invoice_reference(): void
    {
        $old = $this->receive($this->store, '1000', '0.04', ['source_type' => 'receipt', 'received_at' => now()->subDays(5)]);
        $new = $this->receive($this->store, '1000', '0.05');
        $this->travel(1)->minutes();

        $this->transfer('1500');   // بالأقدم أولاً: 1000 من القديمة + 500 من الجديدة

        $source = $this->layers($this->store);
        $dest = $this->layers($this->kitchen);
        $this->assertCount(2, $dest);
        $this->assertSame([['1000.000000', '0.040000'], ['500.000000', '0.050000']], $dest->map(fn ($l) => [$l->qty_in, $l->unit_cost])->all());
        $this->assertSame([$source[0]->id, $source[1]->id], $dest->pluck('origin_layer_id')->all());
        $this->assertEquals($source[0]->received_at, $dest[0]->received_at);   // ترتيب الأقدم أولاً يبقى صحيحاً في الوجهة
        $this->assertSame('transfer_in', $dest[0]->source_type);
        // القيمة انتقلت كما هي: 40 + 25
        $this->assertEquals(65, (float) StockMovement::where('warehouse_id', $this->kitchen->id)->sum('total_cost'));
        $this->assertSame([], app(StockLayerReportService::class)->reconcile()['mismatches']);
    }

    public function test_uncovered_transfer_arrives_provisional_and_is_corrected_when_the_source_is_settled(): void
    {
        InventorySetting::current()->update(['allow_negative_stock' => true]);
        $this->receive($this->store, '1000', '0.04');
        $this->transfer('1500');   // 500 غ بلا دفعة: نقص معلّق في المصدر ودفعة مؤقتة (0.04) في الوجهة

        $provisional = $this->layers($this->kitchen)->firstWhere('is_provisional', true);
        $this->assertSame(['500.000000', '0.040000'], [$provisional->qty_in, $provisional->unit_cost]);

        // دفعة بسعر 0.06 تصل المصدر: تُسوّى الـ 500 منها، والوجهة تأخذ السعر الفعلي لتلك الـ 500.
        $this->receive($this->store, '1000', '0.06');
        $this->assertSame('0.000000', $provisional->fresh()->qty_remaining);
        $split = $this->layers($this->kitchen)->firstWhere('source_type', 'split');
        $this->assertSame(['500.000000', '0.060000'], [$split->qty_remaining, $split->unit_cost]);
        // قيمة الوجهة = 1000 × 0.04 + 500 × 0.06 = 70
        $this->assertEquals(70, (float) StockMovement::where('warehouse_id', $this->kitchen->id)->sum('total_cost'));
        $this->assertSame([], app(StockLayerReportService::class)->reconcile()['mismatches']);
    }

    public function test_count_surplus_creates_a_layer_and_shortage_issues_fifo(): void
    {
        $counts = app(StockCountService::class);
        $this->receive($this->kitchen, '1000', '0.04');
        $this->receive($this->kitchen, '1000', '0.05');

        // عجز 1200: يخرج بالأقدم أولاً = 1000 × 0.04 + 200 × 0.05 = 50
        $count = $counts->createFor($this->kitchen, collect([$this->meat->id]), null, $this->admin);
        $counts->saveCounts($count, [['stock_item_id' => $this->meat->id, 'entered_quantity' => 800]], null);
        $posted = $counts->post($count, $this->admin);
        $this->assertEquals(-50, (float) $posted->variance_value);

        // زيادة 200 بسعر يدخله المستخدم (70 للكيلو = 0.07 للغرام) ← دفعة جديدة بهذا السعر.
        $count = $counts->createFor($this->kitchen, collect([$this->meat->id]), null, $this->admin);
        $counts->saveCounts($count, [['stock_item_id' => $this->meat->id, 'entered_quantity' => 1000, 'unit_cost' => '0.07']], null);
        $counts->post($count, $this->admin);
        $layer = $this->layers($this->kitchen)->firstWhere('source_type', 'adjustment_in');
        $this->assertSame(['200.000000', '0.070000'], [$layer->qty_in, $layer->unit_cost]);
        $this->assertSame([], app(StockLayerReportService::class)->reconcile()['mismatches']);
    }

    public function test_layer_reports_trace_issue_costs_and_check(): void
    {
        $in = $this->receive($this->store, '1000', '0.04');
        $this->transfer('300');
        $layer = StockLayer::where('movement_id', $in->id)->firstOrFail();

        $trace = $this->actingAs($this->admin)->getJson("/api/inventory/layers/{$layer->id}")->assertOk()->json('data');
        $this->assertSame('transfer_out', $trace['allocations'][0]['movement_type']);
        $this->assertCount(1, $trace['children']);

        $available = $this->actingAs($this->admin)->getJson("/api/inventory/items/{$this->meat->id}/layers?warehouse_id={$this->store->id}&quantity=100")
            ->assertOk()->json('data');
        $this->assertSame('700', $available['layers'][0]['qty_remaining']);
        $this->assertSame('4.000000', $available['estimated_cost']);

        $costs = collect($this->actingAs($this->admin)->getJson('/api/inventory/reports/issue-costs')->assertOk()->json('data.types'))->keyBy('type');
        $this->assertSame('12', $costs['transfer_out']['cost']);   // 300 × 0.04

        $this->actingAs($this->admin)->getJson('/api/inventory/reports/layers-check')->assertOk()->assertJsonPath('data.mismatches', []);
        $items = $this->actingAs($this->admin)->getJson("/api/inventory/reports/layers?stock_item_id={$this->meat->id}")->assertOk()->json('data.layers');
        $this->assertCount(2, $items);
        $this->assertSame(1, DB::table('stock_layers')->where('warehouse_id', $this->kitchen->id)->count());
    }
}
