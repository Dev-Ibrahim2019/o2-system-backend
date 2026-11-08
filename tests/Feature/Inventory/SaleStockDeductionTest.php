<?php

namespace Tests\Feature\Inventory;

use App\Events\OrderPaid;
use App\Models\Branch;
use App\Models\Department;
use App\Models\InventorySetting;
use App\Models\Item;
use App\Models\Order;
use App\Models\StockBalance;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\StockSalePosting;
use App\Models\StockUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\RecipeService;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockMovementLine;
use App\Services\Order\OrderPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * خصم المخزون عند البيع حسب الوصفات — والأهم أنه لا يوقف البيع أبداً.
 *
 * سندويش = 1 خبز + 120 غ دجاج + 30 غ ثومية (الثومية: 1000 غ من 200 غ ثوم)  ← ثوم 6 غ لكل سندويش
 * وجبة   = 2 خبز + 250 غ دجاج
 */
class SaleStockDeductionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Branch $branch;

    private Department $dept;

    private Warehouse $kitchen;

    private StockItem $bread;

    private StockItem $chicken;

    private StockItem $garlic;

    private Item $sandwich;

    private Item $meal;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $perms = ['inventory.access', 'inventory.warehouses.all', 'inventory.costs.view', 'inventory.analytics.view', 'inventory.settings.manage'];
        foreach ($perms as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo($perms);

        $this->branch = Branch::factory()->create();
        $this->dept = Department::factory()->create();
        $this->kitchen = Warehouse::create(['name' => 'مطبخ الشاورما', 'code' => 'WH-SH', 'branch_id' => $this->branch->id, 'department_id' => $this->dept->id]);

        $unit = fn (string $n) => StockUnit::firstOrCreate(['name' => $n], ['is_active' => true]);
        $mk = fn (string $name, string $code, string $u, string $type = 'raw') => StockItem::create(['type' => $type, 'name' => $name, 'code' => $code, 'base_unit_id' => $unit($u)->id, 'is_stored' => true]);
        $this->bread = $mk('خبز صاج', 'RM-BRD', 'حبة');
        $this->chicken = $mk('صدر دجاج', 'RM-CHK', 'غرام');
        $this->garlic = $mk('ثوم', 'RM-GAR', 'غرام');
        $sauce = $mk('ثومية', 'IM-SAU', 'غرام', 'intermediate');

        $this->sandwich = Item::factory()->create(['name' => 'Sandwich', 'name_ar' => 'سندويش شاورما', 'department_id' => $this->dept->id]);
        $this->meal = Item::factory()->create(['name' => 'Meal', 'name_ar' => 'وجبة شاورما', 'department_id' => $this->dept->id]);

        $recipes = app(RecipeService::class);
        $recipes->save(null, $sauce->id, ['yield_quantity' => 1000, 'lines' => [['stock_item_id' => $this->garlic->id, 'quantity' => 200]]], $this->admin);
        $recipes->save($this->sandwich->id, null, ['lines' => [
            ['stock_item_id' => $this->bread->id, 'quantity' => 1],
            ['stock_item_id' => $this->chicken->id, 'quantity' => 120],
            ['stock_item_id' => $sauce->id, 'quantity' => 30],
        ]], $this->admin);
        $recipes->save($this->meal->id, null, ['lines' => [
            ['stock_item_id' => $this->bread->id, 'quantity' => 2],
            ['stock_item_id' => $this->chicken->id, 'quantity' => 250],
        ]], $this->admin);

        foreach ([[$this->bread, '100', '0.5'], [$this->chicken, '20000', '0.04'], [$this->garlic, '1000', '0.01']] as [$item, $qty, $cost]) {
            app(StockLedger::class)->post(new StockMovementLine($this->kitchen->id, $item->id, 'opening', $qty, unitCost: $cost, occurredAt: now()->subDay()));
        }
    }

    private function enable(): void
    {
        $this->actingAs($this->admin)->putJson('/api/inventory/settings', ['deduct_sales' => true])
            ->assertOk()->assertJsonPath('data.deduct_sales', true);
        $this->travel(1)->seconds();   // الطلبات التالية تُدفع بعد لحظة التشغيل
    }

    /** طلب غير مدفوع بأسطره، ثم دفعه من نقطة الدفع الحقيقية (OrderPaymentService::markPaid). */
    private function sell(array $lines, ?int $branchId = null): Order
    {
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'T-'.uniqid('', true), 'branch_id' => $branchId ?? $this->branch->id, 'status' => 'served',
            'payment_status' => 'unpaid', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as [$item, $qty, $deptId]) {
            DB::table('order_items')->insert([
                'order_id' => $orderId, 'item_id' => $item->id, 'department_id' => $deptId ?? $item->department_id, 'item_name' => $item->name,
                'quantity' => $qty, 'price' => 10, 'total' => 10 * $qty, 'status' => 'served',
            ]);
        }

        return app(OrderPaymentService::class)->markPaid(Order::findOrFail($orderId));
    }

    private function qty(StockItem $item, ?Warehouse $wh = null): ?string
    {
        return StockBalance::where('warehouse_id', ($wh ?? $this->kitchen)->id)->where('stock_item_id', $item->id)->value('quantity');
    }

    public function test_disabled_by_default_sale_changes_nothing(): void
    {
        $order = $this->sell([[$this->sandwich, 2, null]]);

        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('20000.000000', $this->qty($this->chicken));
        $this->assertSame(0, StockMovement::where('type', 'sale')->count());
        $this->assertNull(StockSalePosting::where('order_id', $order->id)->first());
    }

    public function test_payment_deducts_exploded_recipes_from_department_warehouse(): void
    {
        $this->enable();
        $order = $this->sell([[$this->sandwich, 10, null], [$this->meal, 4, null]]);

        // خبز 10 + 8 = 18 | دجاج 1200 + 1000 = 2200 | ثوم (من الثومية) 10 × 6 = 60
        $this->assertSame('82.000000', $this->qty($this->bread));
        $this->assertSame('17800.000000', $this->qty($this->chicken));
        $this->assertSame('940.000000', $this->qty($this->garlic));

        $posting = StockSalePosting::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(StockSalePosting::POSTED, $posting->status);
        $this->assertSame(5, $posting->movements_count);   // سطرين × مواد كل وصفة (3 + 2)
        // التكلفة: 18 × 0.5 + 2200 × 0.04 + 60 × 0.01 = 9 + 88 + 0.6
        $this->assertSame('97.600000', $posting->total_cost);

        $m = StockMovement::where('source_type', 'order')->where('source_id', $order->id)->where('stock_item_id', $this->chicken->id)->first();
        $this->assertSame('sale', $m->type);
        $this->assertSame($order->order_number, $m->notes);

        // الكرت يعرض رقم الطلب والحساب المقابل.
        $kardex = $this->actingAs($this->admin)->getJson("/api/inventory/kardex?warehouse_id={$this->kitchen->id}&stock_item_id={$this->chicken->id}")->assertOk();
        $this->assertStringContainsString($order->order_number, $kardex->getContent());
    }

    public function test_insufficient_stock_never_blocks_the_sale(): void
    {
        $this->assertFalse(InventorySetting::current()->allow_negative_stock);
        $this->enable();
        // 120 سندويش تحتاج 120 خبزة والرصيد 100.
        $order = $this->sell([[$this->sandwich, 120, null]]);

        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('-20.000000', $this->qty($this->bread));
        $this->assertSame(StockSalePosting::POSTED, StockSalePosting::where('order_id', $order->id)->value('status'));
    }

    public function test_lines_without_recipe_or_warehouse_are_reported_and_the_rest_is_deducted(): void
    {
        $this->enable();
        $noRecipe = Item::factory()->create(['name' => 'Pepsi', 'name_ar' => 'بيبسي', 'department_id' => $this->dept->id]);
        $otherDept = Department::factory()->create();   // قسم بلا مخزن، والفرع بلا مخزن افتراضي
        $order = $this->sell([[$this->sandwich, 1, null], [$noRecipe, 3, null], [$this->meal, 1, $otherDept->id]]);

        $posting = StockSalePosting::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(StockSalePosting::PARTIAL, $posting->status);
        $this->assertEqualsCanonicalizing(['no_recipe', 'no_warehouse'], array_column($posting->issues, 'reason'));
        $this->assertSame('99.000000', $this->qty($this->bread));   // السندويش فقط

        // مع مخزن بيع افتراضي للفرع يُنسب إليه سطر القسم غير المربوط (نفس قاعدة التحليلات).
        $default = Warehouse::create(['name' => 'المخزن الرئيسي', 'code' => 'WH-M', 'branch_id' => $this->branch->id, 'is_default_sales' => true]);
        $this->sell([[$this->meal, 1, $otherDept->id]]);
        $this->assertSame('-2.000000', $this->qty($this->bread, $default));
    }

    public function test_a_failing_deduction_does_not_affect_payment_and_can_be_retried(): void
    {
        $this->enable();
        $this->app->bind(StockLedger::class, fn () => new class extends StockLedger
        {
            public function postMany(array $lines, ?User $by = null, ?bool $allowNegative = null): array
            {
                throw new RuntimeException('database down');
            }
        });

        $order = $this->sell([[$this->sandwich, 2, null]]);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('paid', Order::find($order->id)->payment_status);
        $posting = StockSalePosting::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(StockSalePosting::FAILED, $posting->status);
        $this->assertStringContainsString('database down', $posting->error);
        $this->actingAs($this->admin)->getJson('/api/inventory/settings')->assertJsonPath('data.sales_deduction.failed', 1);

        $this->app->offsetUnset(StockLedger::class);
        $this->actingAs($this->admin)->postJson('/api/inventory/sales-deduction/retry')
            ->assertOk()->assertJsonPath('data.retry.posted', 1)->assertJsonPath('data.sales_deduction.failed', 0);
        $this->assertSame('98.000000', $this->qty($this->bread));

        // إعادة المحاولة ثانيةً، أو إطلاق حدث الدفع مرة أخرى، لا يخصم مرتين.
        $this->actingAs($this->admin)->postJson('/api/inventory/sales-deduction/retry')->assertJsonPath('data.retry.posted', 0);
        event(new OrderPaid(Order::find($order->id)));
        $this->assertSame('98.000000', $this->qty($this->bread));
    }

    public function test_orders_paid_before_enabling_are_not_deducted_retroactively(): void
    {
        $old = $this->sell([[$this->sandwich, 5, null]]);
        $this->travel(1)->seconds();   // الأوقات تُحفظ بالثانية: الطلب القديم قبل لحظة التشغيل
        $this->enable();
        $this->actingAs($this->admin)->postJson('/api/inventory/sales-deduction/retry')->assertJsonPath('data.retry.posted', 0);

        $this->assertNull(StockSalePosting::where('order_id', $old->id)->first());
        $this->assertSame('100.000000', $this->qty($this->bread));
    }

    public function test_cancelling_a_paid_order_reverses_the_deduction(): void
    {
        $this->enable();
        $order = $this->sell([[$this->sandwich, 3, null]]);
        $this->assertSame('97.000000', $this->qty($this->bread));

        Order::find($order->id)->update(['status' => 'cancelled']);

        $this->assertSame('100.000000', $this->qty($this->bread));
        $this->assertSame('20000.000000', $this->qty($this->chicken));
        $this->assertSame(StockSalePosting::REVERSED, StockSalePosting::where('order_id', $order->id)->value('status'));
    }

    public function test_with_deduction_actual_matches_theoretical_until_the_count_finds_waste(): void
    {
        $this->enable();
        $this->sell([[$this->sandwich, 10, null], [$this->meal, 4, null]]);

        $row = collect($this->actingAs($this->admin)->getJson('/api/inventory/analytics/variance?period=today')->assertOk()->json('data.rows'))
            ->firstWhere('stock_item_id', $this->chicken->id);
        $this->assertSame('2200.000000', $row['theoretical_qty']);
        $this->assertSame('2200.000000', $row['actual_qty']);
        $this->assertSame('0.000000', $row['variance_qty']);
    }
}
