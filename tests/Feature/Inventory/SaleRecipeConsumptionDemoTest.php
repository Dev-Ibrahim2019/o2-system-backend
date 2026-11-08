<?php

namespace Tests\Feature\Inventory;

use App\Events\OrderPaid;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Item;
use App\Models\Order;
use App\Models\StockBalance;
use App\Models\StockItem;
use App\Models\StockItemUnit;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\RecipeService;
use App\Services\Inventory\StockCountService;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockMovementLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * تجربة كاملة: هل البيع يخصم من المخزون حسب الوصفات؟
 *
 * الأصناف (مخزن مطبخ الشاورما): خبز صاج (حبة)، صدر دجاج (غ)، ثوم (غ)، زيت (مل)، بطاطا (غ)،
 * وصنف وسيط "ثومية" بوصفة: لكل 1000 غ ثومية ← 200 غ ثوم + 600 مل زيت.
 *
 * وصفات المنيو:
 *   سندويش شاورما دجاج = 1 خبز + 120 غ دجاج + 30 غ ثومية
 *   وجبة شاورما        = 2 خبز + 250 غ دجاج + 50 غ ثومية + 200 غ بطاطا
 *
 * البيع: 40 سندويش + 15 وجبة، مدفوعة.
 *   النظري: خبز 40 + 30 = 70 | دجاج 4800 + 3750 = 8550 غ | ثومية 1200 + 750 = 1950 غ
 *           ← ثوم 1950 × 0.2 = 390 غ، زيت 1950 × 0.6 = 1170 مل | بطاطا 15 × 200 = 3000 غ
 *
 * النتيجة المثبتة هنا (وخصم البيع موقوف، وهو الافتراضي): البيع لا يحرّك الرصيد. الاستهلاك النظري يُحسب في
 * التحليلات من المبيعات × الوصفة، والرصيد ينزل فعلياً بالجرد آخر اليوم، والفرق بينهما هو الهدر/العجز.
 * مع تشغيل "خصم المخزون عند البيع" انظر SaleStockDeductionTest.
 */
class SaleRecipeConsumptionDemoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Warehouse $kitchen;

    /** @var array<string, StockItem> */
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $perms = ['inventory.access', 'inventory.warehouses.all', 'inventory.costs.view', 'inventory.analytics.view'];
        foreach ($perms as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo($perms);
    }

    public function test_sale_does_not_deduct_stock_and_recipes_drive_theoretical_consumption(): void
    {
        // ── 1) التجهيز: مخزن مربوط بقسم الشاورما، أصناف، وصفات، رصيد أول المدة ──
        $branch = Branch::factory()->create();
        $dept = Department::factory()->create();
        $this->kitchen = Warehouse::create(['name' => 'مطبخ الشاورما', 'code' => 'WH-SH', 'branch_id' => $branch->id, 'department_id' => $dept->id]);

        $unit = fn (string $n) => StockUnit::firstOrCreate(['name' => $n], ['is_active' => true]);
        $item = function (string $key, string $name, string $code, string $unitName, string $type = 'raw') use ($unit) {
            $this->s[$key] = StockItem::create(['type' => $type, 'name' => $name, 'code' => $code, 'base_unit_id' => $unit($unitName)->id, 'is_stored' => true]);
        };
        $item('bread', 'خبز صاج', 'RM-BRD', 'حبة');
        $item('chicken', 'صدر دجاج', 'RM-CHK', 'غرام');
        $item('garlic', 'ثوم', 'RM-GAR', 'غرام');
        $item('oil', 'زيت', 'RM-OIL', 'مل');
        $item('potato', 'بطاطا', 'RM-POT', 'غرام');
        $item('sauce', 'ثومية', 'IM-SAU', 'غرام', 'intermediate');
        StockItemUnit::create(['stock_item_id' => $this->s['chicken']->id, 'unit_id' => $unit('كيلوغرام')->id, 'factor' => 1000]);

        $sandwich = Item::factory()->create(['name' => 'Chicken Shawarma', 'name_ar' => 'سندويش شاورما دجاج', 'department_id' => $dept->id]);
        $meal = Item::factory()->create(['name' => 'Shawarma Meal', 'name_ar' => 'وجبة شاورما', 'department_id' => $dept->id]);

        $recipes = app(RecipeService::class);
        $recipes->save(null, $this->s['sauce']->id, ['yield_quantity' => 1000, 'lines' => [
            ['stock_item_id' => $this->s['garlic']->id, 'quantity' => 200],
            ['stock_item_id' => $this->s['oil']->id, 'quantity' => 600],
        ]], $this->admin);
        $recipes->save($sandwich->id, null, ['lines' => [
            ['stock_item_id' => $this->s['bread']->id, 'quantity' => 1],
            ['stock_item_id' => $this->s['chicken']->id, 'quantity' => 120],
            ['stock_item_id' => $this->s['sauce']->id, 'quantity' => 30],
        ]], $this->admin);
        $recipes->save($meal->id, null, ['lines' => [
            ['stock_item_id' => $this->s['bread']->id, 'quantity' => 2],
            ['stock_item_id' => $this->s['chicken']->id, 'quantity' => 250],
            ['stock_item_id' => $this->s['sauce']->id, 'quantity' => 50],
            ['stock_item_id' => $this->s['potato']->id, 'quantity' => 200],
        ]], $this->admin);

        $opening = ['bread' => ['100', '0.5'], 'chicken' => ['20000', '0.04'], 'garlic' => ['2000', '0.01'], 'oil' => ['5000', '0.008'], 'potato' => ['10000', '0.003']];
        foreach ($opening as $k => [$qty, $cost]) {
            app(StockLedger::class)->post(new StockMovementLine($this->kitchen->id, $this->s[$k]->id, 'opening', $qty, unitCost: $cost, occurredAt: now()->subDay()));
        }

        // ── 2) البيع: 40 سندويش + 15 وجبة مدفوعة، مع إطلاق حدث الدفع كما يفعل الكاشير ──
        $order = $this->paidOrder($branch->id, [[$sandwich, 40], [$meal, 15]]);
        event(new OrderPaid($order));

        // الرصيد لم يتغير: لا توجد حركة "بيع" في دفتر المخزون.
        $this->assertSame(0, StockMovement::where('type', 'sale')->count());
        $this->assertSame('20000.000000', $this->balance('chicken'));
        $this->assertSame('100.000000', $this->balance('bread'));

        // ── 3) الاستهلاك النظري من التحليلات (المبيعات × الوصفة، والثومية تُفكَّك إلى ثوم وزيت) ──
        $theoretical = $this->variance()->map(fn ($r) => $r['theoretical_qty']);
        $this->assertSame('70.000000', $theoretical[$this->s['bread']->id]);
        $this->assertSame('8550.000000', $theoretical[$this->s['chicken']->id]);
        $this->assertSame('390.000000', $theoretical[$this->s['garlic']->id]);
        $this->assertSame('1170.000000', $theoretical[$this->s['oil']->id]);
        $this->assertSame('3000.000000', $theoretical[$this->s['potato']->id]);

        // ── 4) جرد آخر اليوم: هو الذي ينزّل الرصيد إلى الموجود فعلاً ──
        $counts = app(StockCountService::class);
        $sheet = $counts->create($this->kitchen, null, 'جرد نهاية اليوم', $this->admin);
        $counts->saveCounts($sheet, [
            ['stock_item_id' => $this->s['bread']->id, 'entered_quantity' => 28],        // المتوقع 30 ← خبزتان ناقصتان
            ['stock_item_id' => $this->s['chicken']->id, 'entered_quantity' => 11200],   // المتوقع 11450 ← 250 غ هدر
            ['stock_item_id' => $this->s['garlic']->id, 'entered_quantity' => 1600],     // المتوقع 1610
            ['stock_item_id' => $this->s['oil']->id, 'entered_quantity' => 3800],        // المتوقع 3830
            ['stock_item_id' => $this->s['potato']->id, 'entered_quantity' => 7000],     // مطابق
        ], null);
        $counts->post($sheet, $this->admin);

        $this->assertSame('11200.000000', $this->balance('chicken'));
        $rows = $this->variance();
        $chicken = $rows[$this->s['chicken']->id];
        $this->assertSame('8800.000000', $chicken['actual_qty']);     // 20000 − 11200
        $this->assertSame('250.000000', $chicken['variance_qty']);    // فعلي − نظري
        $this->assertSame('2.000000', $rows[$this->s['bread']->id]['variance_qty']);
        $this->assertSame('0.000000', $rows[$this->s['potato']->id]['variance_qty']);

        if (getenv('DEMO_PRINT')) {
            $this->printSummary($rows);
        }
    }

    /** @param  array<int, array{0: Item, 1: int}>  $lines */
    private function paidOrder(int $branchId, array $lines): Order
    {
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'DEMO-'.uniqid(), 'branch_id' => $branchId, 'status' => 'closed',
            'payment_status' => 'paid', 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as [$product, $qty]) {
            DB::table('order_items')->insert([
                'order_id' => $orderId, 'item_id' => $product->id, 'department_id' => $product->department_id, 'item_name' => $product->name,
                'quantity' => $qty, 'price' => 10, 'total' => 10 * $qty, 'status' => 'served',
            ]);
        }

        return Order::findOrFail($orderId);
    }

    private function balance(string $key): ?string
    {
        return StockBalance::where('warehouse_id', $this->kitchen->id)->where('stock_item_id', $this->s[$key]->id)->value('quantity');
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function variance()
    {
        return collect($this->actingAs($this->admin)->getJson('/api/inventory/analytics/variance?period=today')->assertOk()->json('data.rows'))
            ->keyBy('stock_item_id');
    }

    private function printSummary($rows): void
    {
        $out = "\nDEMO item | theoretical | actual | variance | variance_value\n";
        foreach ($this->s as $item) {
            $r = $rows[$item->id] ?? null;
            if (! $r) {
                continue;
            }
            $out .= 'DEMO '.$item->code.' | '.(float) $r['theoretical_qty'].' | '.(float) $r['actual_qty'].' | '.(float) $r['variance_qty'].' | '.($r['variance_value'] ?? '-')."\n";
        }
        fwrite(STDERR, $out);
    }
}
