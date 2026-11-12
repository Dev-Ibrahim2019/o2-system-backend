<?php

namespace Tests\Feature\Inventory;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Entry;
use App\Models\StockItem;
use App\Models\StockItemUnit;
use App\Models\StockLayer;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockLayerReportService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * فواتير المشتريات والدفعات (اختبارات المواصفة 1، 6، 8) + المصاريف المحمّلة + تسوية السعر بعد الصرف.
 * لحمة: الأساسية غرام، الكيلو 1000، الكرتونة 10 كغ.
 */
class PurchaseLayerTest extends TestCase
{
    use RefreshDatabase;

    private const PERMS = [
        'inventory.access', 'inventory.warehouses.all', 'inventory.costs.view', 'inventory.documents.manage', 'inventory.documents.post',
        'inventory.documents.reverse', 'purchases.view', 'purchases.create', 'purchases.post', 'purchases.reverse', 'purchases.pay',
    ];

    private User $buyer;

    private Warehouse $store;

    private Supplier $supplier;

    private StockItem $meat;

    private StockUnit $kg;

    private StockUnit $carton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChartOfAccountsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::PERMS as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $this->buyer = User::factory()->create();
        $this->buyer->givePermissionTo(self::PERMS);
        $this->store = Warehouse::create(['name' => 'المخزن الرئيسي', 'code' => 'WH-MAIN', 'branch_id' => Branch::factory()->create()->id]);
        $this->supplier = Supplier::create(['name' => 'ملاحم الأمين', 'code' => 'SUP-1', 'tax_number' => '562100100', 'payment_terms' => 'net15']);
        $this->kg = StockUnit::firstOrCreate(['name' => 'كيلوغرام'], ['is_active' => true]);
        $this->carton = StockUnit::firstOrCreate(['name' => 'كرتونة'], ['is_active' => true]);
        $this->meat = $this->stockItem('لحمة', 'RM-MEAT');
    }

    private function stockItem(string $name, string $code): StockItem
    {
        $item = StockItem::create(['type' => 'raw', 'name' => $name, 'code' => $code,
            'base_unit_id' => StockUnit::firstOrCreate(['name' => 'غرام'], ['is_active' => true])->id, 'is_stored' => true]);
        StockItemUnit::create(['stock_item_id' => $item->id, 'unit_id' => $this->kg->id, 'factor' => 1000]);
        StockItemUnit::create(['stock_item_id' => $item->id, 'unit_id' => $this->carton->id, 'factor' => 10000]);

        return $item;
    }

    /** فاتورة مرحّلة؛ تُرجع رقمها. */
    private function bill(array $lines, array $extra = []): int
    {
        $id = $this->actingAs($this->buyer)->postJson('/api/inventory/purchases', [
            'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->store->id, 'payment_mode' => 'credit', 'lines' => $lines, ...$extra,
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->buyer)->postJson("/api/inventory/purchases/{$id}/post")->assertOk();

        return $id;
    }

    private function line(StockItem $item, string $qty, string $price, ?StockUnit $unit = null, array $extra = []): array
    {
        return ['kind' => 'stock', 'stock_item_id' => $item->id, 'unit_id' => ($unit ?? $this->kg)->id, 'quantity' => $qty, 'unit_price' => $price, ...$extra];
    }

    /** هالك مرحّل؛ $allocations بالوحدة الأساسية (فارغ = الأقدم أولاً). */
    private function waste(string $qty, ?array $allocations = null, ?StockUnit $unit = null): TestResponse
    {
        $id = $this->actingAs($this->buyer)->postJson('/api/inventory/documents', [
            'type' => 'waste', 'warehouse_id' => $this->store->id,
            'lines' => [['stock_item_id' => $this->meat->id, 'unit_id' => ($unit ?? $this->kg)->id, 'quantity' => $qty, 'waste_reason' => 'damaged',
                'allocations' => $allocations]],
        ])->assertCreated()->json('data.id');

        return $this->actingAs($this->buyer)->postJson("/api/inventory/documents/{$id}/post");
    }

    private function layerOf(int $billId): StockLayer
    {
        return StockLayer::where('purchase_bill_id', $billId)->where('stock_item_id', $this->meat->id)->firstOrFail();
    }

    /** @return array<string, string> */
    private function entry(int $txnId): array
    {
        return Entry::where('transaction_id', $txnId)->with('account:id,code')->get()
            ->mapWithKeys(fn ($e) => [$e->account->code => (float) $e->debit > 0 ? 'D'.number_format((float) $e->debit, 2, '.', '') : 'C'.number_format((float) $e->credit, 2, '.', '')])
            ->all();
    }

    public function test_1_two_invoices_then_manual_issue_from_the_second_costs_at_its_price(): void
    {
        $first = $this->bill([$this->line($this->meat, '10', '40')]);
        $second = $this->bill([$this->line($this->meat, '10', '50')]);
        $this->assertSame(['0.040000', '0.050000'], [$this->layerOf($first)->unit_cost, $this->layerOf($second)->unit_cost]);
        $this->assertSame('purchase_invoice', $this->layerOf($first)->source_type);

        $doc = $this->waste('2', [['layer_id' => $this->layerOf($second)->id, 'quantity' => 2000]])->assertOk()->json('data');

        $this->assertEquals(100, (float) $doc['total_cost']);   // 2 كغ × 50 لا بالمتوسط ولا بالأقدم
        $this->assertSame('10000.000000', $this->layerOf($first)->qty_remaining);
        $this->assertSame('8000.000000', $this->layerOf($second)->qty_remaining);
    }

    public function test_6_purchase_return_from_a_partially_consumed_layer(): void
    {
        $billId = $this->bill([$this->line($this->meat, '10', '50', null, ['tax_rate' => 16])]);
        $this->waste('6')->assertOk();   // بالأقدم أولاً من دفعة الفاتورة
        $layer = $this->layerOf($billId);

        // المرتجع 3 كغ من دفعة الفاتورة (افتراضياً) بسعرها: 150، وقيده عكس قيد الفاتورة مع ضريبته.
        $doc = $this->actingAs($this->buyer)->postJson("/api/inventory/purchases/{$billId}/returns", [
            'reason' => 'تالف عند الاستلام', 'lines' => [['purchase_bill_item_id' => $layer->source_line_id, 'quantity' => 3]],
        ])->assertCreated()->json('data');
        $this->assertSame('1000.000000', $layer->fresh()->qty_remaining);
        $this->assertEquals(150, (float) $doc['total_cost']);
        $this->assertSame(['2110' => 'D174.00', '5310' => 'C150.00', '1150' => 'C24.00'], $this->entry((int) $doc['transaction_id']));

        // لا يتجاوز المتبقي في الدفعة (1 كغ).
        $this->actingAs($this->buyer)->postJson("/api/inventory/purchases/{$billId}/returns", [
            'lines' => [['purchase_bill_item_id' => $layer->source_line_id, 'quantity' => 2]],
        ])->assertStatus(422);

        $summary = collect($this->actingAs($this->buyer)->getJson("/api/inventory/purchases/{$billId}/layers")->assertOk()->json('data.layers'))->first();
        $this->assertSame(['10000', '6000', '3000', '1000'], [$summary['qty_in'], $summary['consumed'], $summary['returned'], $summary['remaining']]);

        // عكس المرتجع: الكمية تعود للدفعة نفسها والقيد يُعكس.
        $this->actingAs($this->buyer)->postJson("/api/inventory/purchases/returns/{$doc['id']}/reverse", ['reason' => 'خطأ'])->assertOk();
        $this->assertSame('4000.000000', $layer->fresh()->qty_remaining);
        $this->assertSame([], app(StockLayerReportService::class)->reconcile()['mismatches']);
    }

    public function test_8_bought_by_carton_and_issued_by_kilo(): void
    {
        // 2 كرتونة × 400 ₪ (الكرتونة 10 كغ) → الدفعة 20000 غ بسعر 0.04 للغرام
        $billId = $this->bill([$this->line($this->meat, '2', '400', $this->carton)]);
        $layer = $this->layerOf($billId);
        $this->assertSame(['20000.000000', '0.040000'], [$layer->qty_in, $layer->unit_cost]);

        // صرف 2.5 كغ = 2500 غ × 0.04 = 100
        $doc = $this->waste('2.5')->assertOk()->json('data');
        $this->assertEquals(100, (float) $doc['total_cost']);
        $this->assertSame('17500.000000', $layer->fresh()->qty_remaining);
    }

    public function test_additional_expenses_are_distributed_by_line_value(): void
    {
        $rice = $this->stockItem('رز', 'RM-RICE');
        $freight = (int) Account::where('code', '5210')->value('id');
        // لحمة 10 كغ × 50 = 500، رز 20 كغ × 25 = 500، ونقل 100 يُحمَّل على الأصناف ← 50 لكل سطر.
        $billId = $this->bill([
            $this->line($this->meat, '10', '50'),
            $this->line($rice, '20', '25'),
            ['kind' => 'expense', 'description' => 'نقل', 'account_id' => $freight, 'unit_price' => 100, 'allocate_to_stock' => true],
        ]);

        $this->assertSame('0.055000', $this->layerOf($billId)->unit_cost);   // (500 + 50) ÷ 10000
        $this->assertSame('0.027500', StockLayer::where('purchase_bill_id', $billId)->where('stock_item_id', $rice->id)->value('unit_cost'));
    }

    public function test_cost_adjustment_after_consumption_corrects_issued_and_remaining(): void
    {
        $billId = $this->bill([$this->line($this->meat, '10', '50')]);
        $waste = $this->waste('4')->assertOk()->json('data');   // 4 كغ × 50 = 200
        $layer = $this->layerOf($billId);

        // السعر الصحيح 60 للكيلو: المتبقي 6 كغ يصير 60، والمصروف 4 كغ يُصحَّح بـ +40، والقيد +100 على المورّد.
        $adj = $this->actingAs($this->buyer)->postJson("/api/inventory/purchases/{$billId}/cost-adjustments", [
            'reason' => 'خطأ في سعر الفاتورة', 'lines' => [['purchase_bill_item_id' => $layer->source_line_id, 'unit_cost' => 60]],
        ])->assertCreated()->json('data');

        $this->assertSame('0.060000', $layer->fresh()->unit_cost);
        $this->assertEquals(100, (float) $adj['total_value']);
        $this->assertSame(['5310' => 'D100.00', '2110' => 'C100.00'], $this->entry((int) $adj['transaction_id']));
        $wasteMovement = StockMovement::where('source_type', 'stock_document')->where('source_id', $waste['id'])->firstOrFail();
        $this->assertEquals(-240, (float) StockMovement::where('id', $wasteMovement->id)->orWhere('adjusts_movement_id', $wasteMovement->id)->sum('total_cost'));
        $this->assertEquals(360, (float) StockMovement::where('stock_item_id', $this->meat->id)->sum('total_cost'));   // 6 كغ × 60
        $this->assertSame([], app(StockLayerReportService::class)->reconcile()['mismatches']);

        // الفاتورة المرحّلة لا تُعكس بعد الصرف منها.
        $this->actingAs($this->buyer)->postJson("/api/inventory/purchases/{$billId}/reverse", ['reason' => 'x'])->assertStatus(422);
    }
}
