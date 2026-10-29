<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\Department;
use App\Models\DepartmentBranchSetting;
use App\Models\Item;
use App\Models\StockBatch;
use App\Models\StockCategory;
use App\Models\StockDocument;
use App\Models\StockItem;
use App\Models\StockItemUnit;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\AccountingDay;
use App\Services\Inventory\RecipeService;
use App\Services\Inventory\StockCountService;
use App\Services\Inventory\StockDocumentService;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockMovementLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** منطق O2 للجودة والرقابة: الدفعات، الإنتاجية اليومية والعجز، الشاورما، التفريك، المصنع والمعرض، الإتلاف، الصلاحيات. */
class QualityControlTest extends TestCase
{
    use RefreshDatabase;

    private const PERMS = [
        'inventory.access', 'inventory.warehouses.all', 'inventory.costs.view', 'inventory.documents.manage', 'inventory.documents.post',
        'inventory.documents.reverse', 'inventory.counts.manage', 'inventory.counts.post', 'inventory.analytics.view', 'inventory.warehouses.manage',
    ];

    private User $admin;

    private Branch $branch;

    private Warehouse $main;

    private StockUnit $gram;

    private StockUnit $kg;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::PERMS as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(self::PERMS);
        $this->today = AccountingDay::fromConfig()->of(now());
        $this->branch = Branch::factory()->create();
        $this->main = Warehouse::create(['name' => 'المخزن الرئيسي', 'code' => 'WH-MAIN', 'branch_id' => $this->branch->id, 'is_default_sales' => true]);
        $this->gram = StockUnit::firstOrCreate(['name' => 'غرام'], ['is_active' => true]);
        $this->kg = StockUnit::firstOrCreate(['name' => 'كيلوغرام'], ['is_active' => true]);
    }

    // ── أدوات ─────────────────────────────────────────────────────────────────

    private function item(string $code, string $name, string $type = 'raw', bool $expiry = false): StockItem
    {
        $i = StockItem::create(['type' => $type, 'name' => $name, 'code' => $code, 'base_unit_id' => $this->gram->id, 'is_stored' => true, 'tracks_expiry' => $expiry]);
        StockItemUnit::create(['stock_item_id' => $i->id, 'unit_id' => $this->kg->id, 'factor' => 1000]);

        return $i;
    }

    private function deptWarehouse(string $code, ?Department $dept = null, string $role = 'department'): Warehouse
    {
        return Warehouse::create(['name' => $code, 'code' => $code, 'branch_id' => $this->branch->id,
            'department_id' => ($dept ?? Department::factory()->create())->id, 'role' => $role]);
    }

    private function opening(Warehouse $w, StockItem $i, string $grams, string $cost, bool $yesterday = true): void
    {
        app(StockLedger::class)->post(new StockMovementLine($w->id, $i->id, 'opening', $grams, unitCost: $cost, occurredAt: $yesterday ? now()->subDay() : now()));
    }

    private function doc(array $data): StockDocument
    {
        $svc = app(StockDocumentService::class);

        return $svc->post($svc->create($data, $this->admin), $this->admin);
    }

    private function sale(Item $item, int $qty, float $price = 10): void
    {
        $orderId = DB::table('orders')->insertGetId(['order_number' => 'T-'.uniqid('', true), 'branch_id' => $this->branch->id,
            'status' => 'closed', 'payment_status' => 'paid', 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('order_items')->insert(['order_id' => $orderId, 'item_id' => $item->id, 'department_id' => $item->department_id,
            'item_name' => $item->name, 'quantity' => $qty, 'price' => $price, 'total' => $price * $qty, 'status' => 'served']);
    }

    private function dailyCount(Warehouse $w, array $lines): void
    {
        $svc = app(StockCountService::class);
        $count = $svc->daily($w, $this->today, $this->admin);
        $svc->saveCounts($count, $lines, null);
        $svc->post($count, $this->admin);
    }

    // ── 1) الدفعات ────────────────────────────────────────────────────────────

    public function test_receipt_creates_batches_with_item_code_sequence_and_expiry_status(): void
    {
        $item = $this->item('X1', 'جبنة', 'raw', expiry: true);
        $supplier = Supplier::create(['name' => 'مورد', 'code' => 'S1']);
        foreach (['+200 days', '+60 days', '+10 days'] as $i => $expiry) {
            $this->doc(['type' => 'receipt', 'warehouse_id' => $this->main->id, 'supplier_id' => $supplier->id, 'lines' => [
                ['stock_item_id' => $item->id, 'unit_id' => $this->kg->id, 'quantity' => 5, 'unit_cost' => 20, 'cartons' => 2,
                    'expiry_date' => now()->modify($expiry)->toDateString()],
            ]]);
        }

        $batches = collect($this->actingAs($this->admin)->getJson("/api/inventory/quality/batches?stock_item_id={$item->id}&available=1")->assertOk()->json('data'))->keyBy('code');
        $this->assertSame(['X11', 'X12', 'X13'], $batches->keys()->sort()->values()->all());   // كود الصنف + التسلسل
        $this->assertSame('5000.000000', $batches['X11']['remaining']);
        $this->assertSame('0.020000', $batches['X11']['unit_cost']);                           // 20 ₪/كغ ÷ 1000
        $this->assertSame('ok', $batches['X11']['status']);
        $this->assertSame('warning', $batches['X12']['status']);                               // ≤ 90 يوماً: برتقالي
        $this->assertSame('critical', $batches['X13']['status']);                              // ≤ 30 يوماً: أحمر
        $this->assertSame(0, $batches['X11']['storage_days']);
    }

    public function test_transfer_by_batch_moves_batch_balance_and_cannot_exceed_it(): void
    {
        $item = $this->item('X1', 'جبنة', 'raw', expiry: true);
        $supplier = Supplier::create(['name' => 'مورد', 'code' => 'S1']);
        $this->doc(['type' => 'receipt', 'warehouse_id' => $this->main->id, 'supplier_id' => $supplier->id, 'lines' => [
            ['stock_item_id' => $item->id, 'quantity' => 4000, 'unit_id' => $this->gram->id, 'unit_cost' => 0.02, 'expiry_date' => now()->addYear()->toDateString()],
        ]]);
        $batch = StockBatch::sole();
        $this->opening($this->main, $item, '1000', '0.02');   // 1 كغ قديم بلا دفعة → الرصيد الكلي 5 كغ
        $dept = $this->deptWarehouse('WH-PIZZA');
        $svc = app(StockDocumentService::class);
        $transfer = fn (array $line) => $svc->create(['type' => 'transfer', 'warehouse_id' => $this->main->id, 'to_warehouse_id' => $dept->id,
            'destination' => 'قسم البيتزا', 'lines' => [['stock_item_id' => $item->id, ...$line]]], $this->admin);

        // أكثر من رصيد الدفعة (4.5 كغ من دفعة رصيدها 4 كغ) مرفوض رغم كفاية رصيد الصنف
        $this->assertRejected(fn () => $svc->post($transfer(['quantity' => 4500, 'batch_id' => $batch->id]), $this->admin), 'رصيد الدفعة X11 غير كافٍ');
        // بلا دفعة: يُسمح فقط بقدر المخزون غير الموزّع على دفعات (1 كغ)
        $this->assertRejected(fn () => $svc->post($transfer(['quantity' => 1500]), $this->admin), 'اختر الدفعة');

        $ok = $svc->post($transfer(['quantity' => 1500, 'batch_id' => $batch->id]), $this->admin);
        $this->assertSame('undelivered', $ok->delivery_status);                            // غير مسلّمة افتراضياً
        $bal = DB::table('stock_batch_balances')->where('batch_id', $batch->id)->pluck('quantity', 'warehouse_id');
        $this->assertSame('2500.000000', (string) $bal[$this->main->id]);
        $this->assertSame('1500.000000', (string) $bal[$dept->id]);

        $this->actingAs($this->admin)->patchJson("/api/inventory/documents/{$ok->id}/delivery", ['delivery_status' => 'delivered'])
            ->assertOk()->assertJsonPath('data.delivery_status', 'delivered');
    }

    // ── 2) الإنتاجية اليومية والعجز ───────────────────────────────────────────

    public function test_daily_productivity_cheese_example_and_waste_is_not_deficit(): void
    {
        $dept = Department::factory()->create();
        $pizzaWh = $this->deptWarehouse('WH-PIZZA', $dept);
        $cheese = $this->item('CH', 'جبنة');
        $calzone = Item::factory()->create(['name_ar' => 'كلزوني', 'department_id' => $dept->id]);
        $pizza = Item::factory()->create(['name_ar' => 'بيتزا', 'department_id' => $dept->id]);
        $recipes = app(RecipeService::class);
        $recipes->save($calzone->id, null, ['lines' => [['stock_item_id' => $cheese->id, 'quantity' => 100]]], $this->admin);
        $recipes->save($pizza->id, null, ['lines' => [['stock_item_id' => $cheese->id, 'quantity' => 100]]], $this->admin);

        $this->opening($this->main, $cheese, '10000', '0.03');
        $this->opening($pizzaWh, $cheese, '3000', '0.03');                                       // رصيد بداية اليوم 3 كغ
        $this->doc(['type' => 'transfer', 'warehouse_id' => $this->main->id, 'to_warehouse_id' => $pizzaWh->id,
            'lines' => [['stock_item_id' => $cheese->id, 'quantity' => 2, 'unit_id' => $this->kg->id]]]);   // محوَّل اليوم 2 كغ
        $this->doc(['type' => 'waste', 'warehouse_id' => $pizzaWh->id,
            'lines' => [['stock_item_id' => $cheese->id, 'quantity' => 200, 'waste_reason' => 'damaged']]]);  // إتلاف 0.2 كغ
        $this->sale($calzone, 10);
        $this->sale($pizza, 10);
        $this->dailyCount($pizzaWh, [['stock_item_id' => $cheese->id, 'entered_quantity' => 2.6, 'unit_id' => $this->kg->id]]);  // المتبقي 2.6 كغ

        $row = collect($this->actingAs($this->admin)->getJson("/api/inventory/quality/productivity?warehouse_id={$pizzaWh->id}&day={$this->today}")
            ->assertOk()->json('data.rows'))->firstWhere('stock_item_id', $cheese->id);

        $this->assertSame('5000.000000', $row['received']);      // 3 + 2 كغ
        $this->assertSame('2600.000000', $row['remaining']);
        $this->assertSame('200.000000', $row['waste']);
        $this->assertSame('2200.000000', $row['consumed']);      // 5 − 2.6 − 0.2
        $this->assertSame('2000.000000', $row['theoretical']);   // (10 + 10) × 100 غ = 2 كغ
        $this->assertSame('200.000000', $row['deficit']);        // الإتلاف لا يُحسب عجزاً
        $this->assertSame('10.00', $row['deficit_percent']);
        $this->assertTrue($row['alert']);
    }

    public function test_daily_count_treats_empty_remaining_as_zero(): void
    {
        $wh = $this->deptWarehouse('WH-D');
        $a = $this->item('A', 'طماطم');
        $b = $this->item('B', 'خس');
        $this->opening($wh, $a, '1000', '0.01');
        $this->opening($wh, $b, '500', '0.01');

        $this->dailyCount($wh, [['stock_item_id' => $a->id, 'entered_quantity' => 400]]);   // الخس فارغ = صفر

        $this->assertSame('400.000000', (string) DB::table('stock_balances')->where('warehouse_id', $wh->id)->where('stock_item_id', $a->id)->value('quantity'));
        $this->assertSame('0.000000', (string) DB::table('stock_balances')->where('warehouse_id', $wh->id)->where('stock_item_id', $b->id)->value('quantity'));
    }

    // ── 3) الشاورما ───────────────────────────────────────────────────────────

    public function test_shawarma_rules_skewers_spice_yield_and_meals(): void
    {
        $dept = Department::factory()->create();
        $wh = $this->deptWarehouse('WH-SHW', $dept);
        $meatCat = StockCategory::create(['name' => 'لحوم', 'is_active' => true]);
        $lamb = $this->item('LAMB', 'لحم');
        $lamb->categories()->attach($meatCat->id);
        $spice = $this->item('SPICE', 'بهار');
        $bread = $this->item('BREAD', 'خبز');
        $skewer = $this->item('SKW', 'سيخ شاورما', 'intermediate');
        DepartmentBranchSetting::create(['department_id' => $dept->id, 'branch_id' => $this->branch->id, 'is_shawarma' => true,
            'meat_category_id' => $meatCat->id, 'spice_item_id' => $spice->id, 'skewer_item_id' => $skewer->id]);
        $sandwich = Item::factory()->create(['name_ar' => 'ساندويش شاورما', 'department_id' => $dept->id]);
        app(RecipeService::class)->save($sandwich->id, null, ['lines' => [['stock_item_id' => $bread->id, 'quantity' => 50]]], $this->admin);

        $this->opening($wh, $lamb, '60000', '0.04');
        $this->opening($wh, $spice, '2000', '0.02');
        $this->opening($wh, $bread, '5000', '0.005');
        $this->sale($sandwich, 80);   // 80 × 10 ₪ = 800 ₪

        // جرد اليوم: باقي اللحم 10 كغ (مشرّح 3، مبهّر 2)، البهار 400 غ، الخبز 1 كغ
        $this->dailyCount($wh, [
            ['stock_item_id' => $lamb->id, 'entered_quantity' => 10, 'unit_id' => $this->kg->id, 'dissected' => 3, 'marinated' => 2],
            ['stock_item_id' => $spice->id, 'entered_quantity' => 400],
            ['stock_item_id' => $bread->id, 'entered_quantity' => 1000],
        ]);

        // أسياخ: 20 + 25 = 45 كغ ≤ 50 كغ لحم مستهلك
        $first = $this->doc(['type' => 'skewer', 'warehouse_id' => $wh->id, 'lines' => [
            ['stock_item_id' => $skewer->id, 'quantity' => 20, 'unit_id' => $this->kg->id],
            ['stock_item_id' => $skewer->id, 'quantity' => 25, 'unit_id' => $this->kg->id],
        ]]);
        $codes = $first->lines->map(fn ($l) => $l->createdBatch->code)->all();
        $this->assertSame(['SK-000001', 'SK-000002'], $codes);

        // تجاوز اللحوم المستهلكة مرفوض: 45 + 10 > 50
        $svc = app(StockDocumentService::class);
        $over = $svc->create(['type' => 'skewer', 'warehouse_id' => $wh->id, 'lines' => [['stock_item_id' => $skewer->id, 'quantity' => 10, 'unit_id' => $this->kg->id]]], $this->admin);
        try {
            $svc->post($over, $this->admin);
            $this->fail('كان يجب رفض الأسياخ');
        } catch (\App\Services\Inventory\InventoryRuleException $e) {
            $this->assertStringContainsString('أكبر من اللحوم المستهلكة', $e->getMessage());
        }

        // الرقم التسلسلي لا يتكرر حتى بعد عكس مستند
        $svc->reverse($first, 'تجربة', $this->admin);
        $again = $this->doc(['type' => 'skewer', 'warehouse_id' => $wh->id, 'lines' => [['stock_item_id' => $skewer->id, 'quantity' => 20, 'unit_id' => $this->kg->id]]]);
        $this->assertSame('SK-000003', $again->lines->first()->createdBatch->code);

        $this->actingAs($this->admin)->postJson('/api/inventory/quality/skewers/'.$again->lines->first()->created_batch_id.'/export')->assertOk();
        $this->actingAs($this->admin)->postJson('/api/inventory/quality/skewers/'.$again->lines->first()->created_batch_id.'/export')->assertStatus(422);

        $day = $this->actingAs($this->admin)->getJson("/api/inventory/quality/shawarma?warehouse_id={$wh->id}")->assertOk()->json('data.days.0');
        $this->assertSame('50000.000000', $day['meat_consumed']);           // 60 − 10 كغ
        $this->assertSame('1500.000000', $day['spice_theoretical']);        // (50 ÷ 50) × 1.5 كغ
        $this->assertSame('1600.000000', $day['spice_actual']);             // 2000 − 400 غ
        $this->assertSame('100.000000', $day['spice_deficit']);
        $this->assertSame('20000.000000', $day['exported_weight']);
        $this->assertSame('40.00', $day['yield_per_kg']);                   // 800 ₪ ÷ 20 كغ
        $this->assertSame('5000.000000', $day['meat_details'][0]['undissected']);   // 10 − 3 − 2 كغ

        // الوجبات: 80 ساندويش × 50 غ خبز = 4 كغ
        $breadRow = collect($this->actingAs($this->admin)->getJson("/api/inventory/quality/productivity?warehouse_id={$wh->id}&day={$this->today}")->json('data.rows'))
            ->firstWhere('stock_item_id', $bread->id);
        $this->assertSame('4000.000000', $breadRow['theoretical']);
        $this->assertSame('0.000000', $breadRow['deficit']);
    }

    // ── 4) التفريك ────────────────────────────────────────────────────────────

    public function test_breakdown_loss_prime_ratio_and_cost_allocation(): void
    {
        $whole = $this->item('WHOLE', 'دجاج كامل');
        $parts = collect(['BRST' => 'صدر', 'THGH' => 'فخذ', 'WING' => 'جناح', 'BACK' => 'ظهر'])->map(fn ($n, $c) => $this->item($c, $n));
        $this->opening($this->main, $whole, '10000', '0.02');   // 10 كغ بقيمة 200 ₪

        $doc = $this->doc(['type' => 'breakdown', 'warehouse_id' => $this->main->id, 'lines' => [
            ['stock_item_id' => $whole->id, 'direction' => 'in', 'quantity' => 10, 'unit_id' => $this->kg->id],
            ['stock_item_id' => $parts['BRST']->id, 'direction' => 'out', 'quantity' => 3, 'unit_id' => $this->kg->id, 'is_prime' => true],
            ['stock_item_id' => $parts['THGH']->id, 'direction' => 'out', 'quantity' => 3, 'unit_id' => $this->kg->id, 'is_prime' => true],
            ['stock_item_id' => $parts['WING']->id, 'direction' => 'out', 'quantity' => 1.5, 'unit_id' => $this->kg->id],
            ['stock_item_id' => $parts['BACK']->id, 'direction' => 'out', 'quantity' => 1.5, 'unit_id' => $this->kg->id],
        ]]);

        $stats = app(StockDocumentService::class)->breakdownStats($doc);
        $this->assertSame('10.00', $stats['loss_percent']);    // (10 − 9) ÷ 10
        $this->assertSame('60.00', $stats['prime_percent']);   // (3 + 3) ÷ 10

        // قيمة 200 ₪ موزّعة بالوزن على 9 كغ → 22.222 ₪/كغ، والناتج دفعات جديدة
        $breast = StockBatch::where('stock_item_id', $parts['BRST']->id)->sole();
        $this->assertSame('BRST1', $breast->code);
        $this->assertSame('0.022222', $breast->unit_cost);
        $this->assertSame('0.000000', (string) DB::table('stock_balances')->where('stock_item_id', $whole->id)->value('quantity'));
    }

    // ── 5) المصنع والمعرض ─────────────────────────────────────────────────────

    public function test_factory_production_showroom_sales_and_protections(): void
    {
        $dept = Department::factory()->create();
        $raw = $this->deptWarehouse('WH-RAW', $dept, 'factory_raw');
        $products = $this->deptWarehouse('WH-PRD', $dept, 'factory_products');
        $showroom = $this->deptWarehouse('WH-SHOW', $dept, 'showroom');
        $flour = $this->item('FLOUR', 'طحين');
        $sugar = $this->item('SUGAR', 'سكر');
        $cake = $this->item('CAKE', 'كيك', 'finished');
        // الوصفة لكل 1 كغ منتج: 500 غ طحين + 300 غ سكر
        app(RecipeService::class)->save(null, $cake->id, ['yield_quantity' => 1000, 'lines' => [
            ['stock_item_id' => $flour->id, 'quantity' => 500], ['stock_item_id' => $sugar->id, 'quantity' => 300],
        ]], $this->admin);
        $this->opening($raw, $flour, '10000', '0.002');
        $this->opening($raw, $sugar, '5000', '0.004');

        $prod = $this->doc(['type' => 'production', 'warehouse_id' => $raw->id, 'to_warehouse_id' => $products->id,
            'lines' => [['stock_item_id' => $cake->id, 'quantity' => 4, 'unit_id' => $this->kg->id]]]);
        $batch = $prod->lines->first()->createdBatch;
        $this->assertSame('CAKE1', $batch->code);                       // رقم المنتج التلقائي
        $this->assertSame('8.800000', (string) $prod->total_cost);      // 4000 × (500×0.002 + 300×0.004) ÷ 1000

        // المفترض من مخزن المواد = 4 كغ × الوصفة: طحين 2 كغ، سكر 1.2 كغ
        $rows = collect($this->actingAs($this->admin)->getJson("/api/inventory/quality/productivity?warehouse_id={$raw->id}&day={$this->today}")->json('data.rows'))->keyBy('stock_item_id');
        $this->assertSame('2000.000000', $rows[$flour->id]['theoretical']);
        $this->assertSame('1200.000000', $rows[$sugar->id]['theoretical']);

        // صادر للمعرض 3 كغ، ثم بيع 2.5 كغ بـ 30 ₪/كغ
        $export = $this->doc(['type' => 'transfer', 'warehouse_id' => $products->id, 'to_warehouse_id' => $showroom->id,
            'lines' => [['stock_item_id' => $cake->id, 'quantity' => 3, 'unit_id' => $this->kg->id, 'batch_id' => $batch->id]]]);
        $sale = $this->doc(['type' => 'showroom_sale', 'warehouse_id' => $showroom->id,
            'lines' => [['stock_item_id' => $cake->id, 'quantity' => 2.5, 'unit_id' => $this->kg->id, 'sale_price' => 30]]]);
        $this->assertSame('75.000000', (string) $sale->revenue);

        $svc = app(StockDocumentService::class);
        $more = $svc->create(['type' => 'showroom_sale', 'warehouse_id' => $showroom->id,
            'lines' => [['stock_item_id' => $cake->id, 'quantity' => 1, 'unit_id' => $this->kg->id, 'sale_price' => 30]]], $this->admin);
        $this->assertRejected(fn () => $svc->post($more, $this->admin));             // المتوفر 0.5 كغ فقط
        $this->assertRejected(fn () => $svc->reverse($export, 'x', $this->admin));   // لا يُلغى صادر بيع منه
        $this->assertRejected(fn () => $svc->reverse($prod, 'x', $this->admin));     // لا يُلغى إنتاج صُدِّر

        // مدة التخزين في المعرض: تنبيه بعد 3 أيام (إعداد)
        DB::table('stock_batch_balances')->where('batch_id', $batch->id)->where('warehouse_id', $showroom->id)->update(['arrived_at' => now()->subDays(5)]);
        $row = collect($this->actingAs($this->admin)->getJson("/api/inventory/quality/batches?warehouse_id={$showroom->id}&available=1")->json('data'))->first();
        $this->assertSame(5, $row['storage_days']);
        $this->assertTrue($row['showroom_overdue']);
    }

    // ── 6) الإتلاف ومحضره ─────────────────────────────────────────────────────

    public function test_waste_from_batch_minutes_and_reversal_returns_quantity(): void
    {
        $item = $this->item('X1', 'جبنة', 'raw', expiry: true);
        $supplier = Supplier::create(['name' => 'مورد', 'code' => 'S1']);
        $this->doc(['type' => 'receipt', 'warehouse_id' => $this->main->id, 'supplier_id' => $supplier->id, 'lines' => [
            ['stock_item_id' => $item->id, 'quantity' => 3, 'unit_id' => $this->kg->id, 'unit_cost' => 30, 'expiry_date' => now()->addYear()->toDateString()],
        ]]);
        $batch = StockBatch::sole();
        $waste = $this->doc(['type' => 'waste', 'warehouse_id' => $this->main->id,
            'lines' => [['stock_item_id' => $item->id, 'quantity' => 1, 'unit_id' => $this->kg->id, 'batch_id' => $batch->id, 'waste_reason' => 'expired']]]);

        $minutes = $this->actingAs($this->admin)->getJson("/api/inventory/quality/waste-minutes?warehouse_id={$this->main->id}&day={$this->today}")->assertOk();
        $this->assertSame('X11', $minutes->json('data.rows.0.batch'));
        $this->assertSame('منتهي الصلاحية', $minutes->json('data.rows.0.reason'));
        $this->assertSame('30.00', $minutes->json('data.total_value'));
        $this->assertSame('2000.000000', (string) DB::table('stock_batch_balances')->where('batch_id', $batch->id)->value('quantity'));

        // "حذف" الإتلاف = عكسه: تعود الكمية للدفعة
        app(StockDocumentService::class)->reverse($waste, 'خطأ إدخال', $this->admin);
        $this->assertSame('3000.000000', (string) DB::table('stock_batch_balances')->where('batch_id', $batch->id)->value('quantity'));
    }

    // ── 7) الصلاحيات ─────────────────────────────────────────────────────────

    public function test_view_only_access_can_read_but_not_write(): void
    {
        $wh = $this->deptWarehouse('WH-V');
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['inventory.access', 'inventory.counts.manage', 'inventory.analytics.view']);
        $wh->users()->attach($viewer->id, ['created_at' => now(), 'access_level' => 'view']);

        $this->actingAs($viewer)->getJson("/api/inventory/quality/productivity?warehouse_id={$wh->id}")->assertOk();
        $this->actingAs($viewer)->postJson('/api/inventory/quality/daily-count', ['warehouse_id' => $wh->id])
            ->assertForbidden()->assertJsonPath('message', 'صلاحيتك على هذا المخزن مشاهدة فقط.');
        $this->actingAs($viewer)->getJson("/api/inventory/quality/productivity?warehouse_id={$this->main->id}")->assertForbidden();
    }

    public function test_recipe_shows_last_batch_price_and_weight_share(): void
    {
        $flour = $this->item('FLOUR', 'طحين', 'raw', expiry: true);
        $sugar = $this->item('SUGAR', 'سكر');
        $cake = $this->item('CAKE', 'كيك', 'finished');
        $supplier = Supplier::create(['name' => 'مورد', 'code' => 'S1']);
        $this->doc(['type' => 'receipt', 'warehouse_id' => $this->main->id, 'supplier_id' => $supplier->id, 'lines' => [
            ['stock_item_id' => $flour->id, 'quantity' => 1, 'unit_id' => $this->kg->id, 'unit_cost' => 3, 'expiry_date' => now()->addYear()->toDateString()],
        ]]);
        $recipe = app(RecipeService::class)->save(null, $cake->id, ['yield_quantity' => 1000, 'lines' => [
            ['stock_item_id' => $flour->id, 'quantity' => 600], ['stock_item_id' => $sugar->id, 'quantity' => 400],
        ]], $this->admin);

        $ingredients = collect(app(RecipeService::class)->unitCost($recipe, $this->today)['ingredients'])->keyBy('stock_item_id');
        $this->assertSame('0.003000', $ingredients[$flour->id]['last_batch_price']);
        $this->assertSame('60.00', $ingredients[$flour->id]['weight_percent']);   // 600 ÷ 1000
    }

    private function assertRejected(callable $fn, ?string $message = null): void
    {
        try {
            $fn();
        } catch (\App\Services\Inventory\InventoryRuleException $e) {
            if ($message !== null) {
                $this->assertStringContainsString($message, $e->getMessage());
            } else {
                $this->addToAssertionCount(1);
            }

            return;
        }
        $this->fail('كان يجب رفض العملية');
    }
}
