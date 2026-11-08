<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Item;
use App\Models\Recipe;
use App\Models\StockBalance;
use App\Models\StockCount;
use App\Models\StockItem;
use App\Models\StockItemUnit;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\AccountingDay;
use App\Services\Inventory\RecipeService;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockMovementLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** المرحلة 4: الوصفات بنسخ وتاريخ سريان، والوصفات الفرعية، والجرد الفعلي، وربط القسم بالمخزن. */
class RecipeAndCountTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = [
        'inventory.access', 'inventory.warehouses.all', 'inventory.warehouses.manage', 'inventory.costs.view',
        'inventory.recipes.manage', 'inventory.counts.manage', 'inventory.counts.post',
    ];

    private User $admin;

    private Warehouse $kitchen;

    private StockItem $chicken;

    private StockItem $garlic;

    private StockItem $oil;

    private StockItem $sauce;

    private Item $shawarma;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->user(self::ADMIN);
        $this->kitchen = Warehouse::create(['name' => 'قسم الشاورما', 'code' => 'WH-SH', 'branch_id' => Branch::factory()->create()->id]);
        $this->chicken = $this->item('صدر دجاج', 'RM-CHK', 'raw', ['كيلوغرام' => 1000, 'كرتونة' => 10000]);
        $this->garlic = $this->item('ثوم', 'RM-GAR');
        $this->oil = $this->item('زيت', 'RM-OIL');
        $this->sauce = $this->item('ثومية', 'IM-SAUCE', 'intermediate');
        $this->shawarma = Item::factory()->create(['name' => 'Shawarma', 'name_ar' => 'شاورما عادي']);
        $this->today = AccountingDay::fromConfig()->of(now());
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

    /** @param  array<string, int>  $alt */
    private function item(string $name, string $code, string $type = 'raw', array $alt = []): StockItem
    {
        $item = StockItem::create(['type' => $type, 'name' => $name, 'code' => $code, 'base_unit_id' => $this->unit('غرام')->id, 'is_stored' => true]);
        foreach ($alt as $unit => $factor) {
            StockItemUnit::create(['stock_item_id' => $item->id, 'unit_id' => $this->unit($unit)->id, 'factor' => $factor]);
        }

        return $item;
    }

    private function saveRecipe(string $type, int $id, array $lines, array $extra = []): TestResponse
    {
        return $this->actingAs($this->admin)->postJson("/api/inventory/recipes/{$type}/{$id}", ['lines' => $lines, ...$extra]);
    }

    private function balance(StockItem $item): ?StockBalance
    {
        return StockBalance::where('warehouse_id', $this->kitchen->id)->where('stock_item_id', $item->id)->first();
    }

    private function seedStock(StockItem $item, string $grams, string $costPerGram): void
    {
        app(StockLedger::class)->post(new StockMovementLine($this->kitchen->id, $item->id, 'opening', $grams, unitCost: $costPerGram));
    }

    // ── الوصفات ───────────────────────────────────────────────────────────────

    public function test_recipe_line_is_converted_to_base_unit(): void
    {
        $this->saveRecipe('product', $this->shawarma->id, [
            ['stock_item_id' => $this->chicken->id, 'unit_id' => $this->unit('كيلوغرام')->id, 'quantity' => 0.12],
        ])->assertCreated();

        $line = Recipe::sole()->lines()->sole();
        $this->assertSame('0.120000', $line->quantity);
        $this->assertSame('120.000000', $line->base_quantity);   // 0.12 كغ × 1000
        $this->assertSame($this->today, Recipe::sole()->effective_from->toDateString());
    }

    public function test_versions_by_effective_date_keep_history(): void
    {
        $service = app(RecipeService::class);
        $later = now()->addDays(2)->toDateString();
        // نسخة قديمة سارية منذ 10 أيام (تُنشأ مباشرة لأن الواجهة ترفض التاريخ السابق).
        $old = Recipe::create(['item_id' => $this->shawarma->id, 'effective_from' => now()->subDays(10)->toDateString(), 'yield_quantity' => 1]);
        $old->lines()->create(['stock_item_id' => $this->chicken->id, 'unit_id' => $this->unit('غرام')->id, 'quantity' => 100, 'base_quantity' => 100]);

        $this->saveRecipe('product', $this->shawarma->id, [['stock_item_id' => $this->chicken->id, 'quantity' => 130]], ['effective_from' => $later])
            ->assertCreated();

        $this->assertSame('100.000000', $service->versionAt($this->shawarma->id, null, $this->today)->lines->first()->base_quantity);
        $this->assertSame('130.000000', $service->versionAt($this->shawarma->id, null, $later)->lines->first()->base_quantity);
        $this->assertNull($service->versionAt($this->shawarma->id, null, now()->subDays(20)->toDateString()));

        // تاريخ سابق مرفوض، والنسخة التي بدأ سريانها لا تُحذف.
        $this->saveRecipe('product', $this->shawarma->id, [['stock_item_id' => $this->chicken->id, 'quantity' => 1]], ['effective_from' => now()->subDays(3)->toDateString()])
            ->assertStatus(422);
        $this->actingAs($this->admin)->deleteJson("/api/inventory/recipes/versions/{$old->id}")->assertStatus(422);
    }

    public function test_saving_same_date_replaces_the_version(): void
    {
        $this->saveRecipe('product', $this->shawarma->id, [['stock_item_id' => $this->chicken->id, 'quantity' => 100]])->assertCreated();
        $this->saveRecipe('product', $this->shawarma->id, [['stock_item_id' => $this->chicken->id, 'quantity' => 120]])->assertCreated();

        $this->assertSame(1, Recipe::count());
        $this->assertSame('120.000000', Recipe::sole()->lines()->sole()->base_quantity);
    }

    public function test_sub_recipe_is_exploded_to_raw_materials(): void
    {
        // ثومية: 1000 غ ناتج من 200 غ ثوم + 800 غ زيت
        $this->saveRecipe('intermediate', $this->sauce->id, [
            ['stock_item_id' => $this->garlic->id, 'quantity' => 200],
            ['stock_item_id' => $this->oil->id, 'quantity' => 800],
        ], ['yield_quantity' => 1000])->assertCreated();
        // شاورما: 120 غ دجاج + 30 غ ثومية
        $this->saveRecipe('product', $this->shawarma->id, [
            ['stock_item_id' => $this->chicken->id, 'quantity' => 120],
            ['stock_item_id' => $this->sauce->id, 'quantity' => 30],
        ])->assertCreated();

        $service = app(RecipeService::class);
        $exploded = $service->explode($service->versionAt($this->shawarma->id, null, $this->today), $this->today);

        $this->assertSame(0, bccomp($exploded[$this->chicken->id], '120', 6));
        $this->assertSame(0, bccomp($exploded[$this->garlic->id], '6', 6));    // 30 × 200 ÷ 1000
        $this->assertSame(0, bccomp($exploded[$this->oil->id], '24', 6));      // 30 × 800 ÷ 1000
        $this->assertArrayNotHasKey($this->sauce->id, $exploded);
    }

    public function test_cyclic_sub_recipes_are_rejected(): void
    {
        $other = $this->item('صوص', 'IM-OTHER', 'intermediate');
        $this->saveRecipe('intermediate', $this->sauce->id, [['stock_item_id' => $other->id, 'quantity' => 10]])->assertCreated();

        $this->saveRecipe('intermediate', $other->id, [['stock_item_id' => $this->sauce->id, 'quantity' => 10]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'الوصفة تحتوي حلقة: المنتج الوسيط يدخل في وصفته نفسها.');
        $this->assertSame(0, Recipe::where('stock_item_id', $other->id)->count());
    }

    public function test_recipe_cost_and_food_cost_percent(): void
    {
        $this->seedStock($this->chicken, '10000', '0.04');   // 40 ₪/كغ
        $branch = Branch::factory()->create();
        DB::table('branch_item')->insert(['branch_id' => $branch->id, 'item_id' => $this->shawarma->id, 'price' => 12, 'is_active' => true]);
        $this->saveRecipe('product', $this->shawarma->id, [['stock_item_id' => $this->chicken->id, 'quantity' => 120]])->assertCreated();

        $res = $this->actingAs($this->admin)->getJson("/api/inventory/recipes/product/{$this->shawarma->id}")->assertOk();
        $this->assertSame('4.800000', $res->json('data.costing.cost'));           // 120 × 0.04
        $this->assertSame('40.00', $res->json('data.food_cost_percent'));         // 4.8 ÷ 12 × 100
        $this->assertSame('صدر دجاج', $res->json('data.costing.ingredients.0.name'));

        $row = collect($this->actingAs($this->admin)->getJson('/api/inventory/recipes?type=product&per_page=100')->json('data.data'))
            ->firstWhere('id', $this->shawarma->id);
        $this->assertTrue($row['has_recipe']);
        $this->assertSame('4.800000', $row['unit_cost']);

        $viewer = $this->user(['inventory.access']);
        $hidden = $this->actingAs($viewer)->getJson("/api/inventory/recipes/product/{$this->shawarma->id}")->assertOk();
        $this->assertNull($hidden->json('data.costing.cost'));
        $this->assertNull($hidden->json('data.food_cost_percent'));
    }

    public function test_recipe_rules(): void
    {
        $finished = $this->item('منتج نهائي', 'FIN-1', 'finished');
        // مكوّن من نوع منتج نهائي مرفوض، والمكوّن المكرر مرفوض، والوصفة الفرعية لغير الوسيط غير موجودة.
        $this->saveRecipe('product', $this->shawarma->id, [['stock_item_id' => $finished->id, 'quantity' => 1]])->assertStatus(422);
        $this->saveRecipe('product', $this->shawarma->id, [
            ['stock_item_id' => $this->chicken->id, 'quantity' => 1],
            ['stock_item_id' => $this->chicken->id, 'quantity' => 2],
        ])->assertStatus(422);
        $this->saveRecipe('intermediate', $this->chicken->id, [['stock_item_id' => $this->garlic->id, 'quantity' => 1]])->assertNotFound();

        $this->actingAs($this->user(['inventory.access']))
            ->postJson("/api/inventory/recipes/product/{$this->shawarma->id}", ['lines' => [['stock_item_id' => $this->chicken->id, 'quantity' => 1]]])
            ->assertForbidden();
    }

    // ── الجرد ─────────────────────────────────────────────────────────────────

    private function countSheet(): int
    {
        return $this->actingAs($this->admin)->postJson('/api/inventory/counts', ['warehouse_id' => $this->kitchen->id])
            ->assertCreated()->json('data.id');
    }

    public function test_count_posts_shortage_and_surplus_as_adjustments(): void
    {
        $this->seedStock($this->chicken, '30000', '0.04');   // 30 كغ
        $this->seedStock($this->garlic, '1000', '0.01');
        $id = $this->countSheet();

        $sheet = $this->actingAs($this->admin)->getJson("/api/inventory/counts/{$id}")->assertOk();
        $chickenLine = collect($sheet->json('data.lines'))->firstWhere('stock_item_id', $this->chicken->id);
        $this->assertSame('30000.000000', $chickenLine['expected_quantity']);

        // دجاج: 2 كرتونة = 20000 غ (عجز 10 كغ). ثوم: 1200 غ (زيادة 200 غ). زيت: لم يُعدّ.
        $this->actingAs($this->admin)->putJson("/api/inventory/counts/{$id}", ['lines' => [
            ['stock_item_id' => $this->chicken->id, 'unit_id' => $this->unit('كرتونة')->id, 'entered_quantity' => 2],
            ['stock_item_id' => $this->garlic->id, 'entered_quantity' => 1200],
        ]])->assertOk();

        $res = $this->actingAs($this->admin)->postJson("/api/inventory/counts/{$id}/post")->assertOk()->assertJsonPath('data.status', 'posted');

        $this->assertSame('20000.000000', $this->balance($this->chicken)->quantity);
        $this->assertSame('1200.000000', $this->balance($this->garlic)->quantity);
        $this->assertNull($this->balance($this->oil));

        $lines = collect($res->json('data.lines'))->keyBy('stock_item_id');
        $this->assertSame('-10000.000000', $lines[$this->chicken->id]['variance_quantity']);
        $this->assertSame('-400.000000', $lines[$this->chicken->id]['variance_value']);   // 10000 × 0.04
        $this->assertSame('2.000000', $lines[$this->garlic->id]['variance_value']);        // 200 × 0.01
        $this->assertNull($lines[$this->oil->id]['variance_quantity']);
        $this->assertSame('-398.000000', $res->json('data.variance_value'));

        $this->assertSame(2, StockMovement::where('type', 'adjustment')->where('source_type', 'stock_count')->count());
        $this->assertSame($this->today, StockCount::find($id)->count_date->toDateString());
    }

    public function test_count_variance_uses_balance_at_posting_not_at_sheet_creation(): void
    {
        $this->seedStock($this->chicken, '10000', '0.04');
        $id = $this->countSheet();
        // بيع بين إنشاء الورقة والترحيل.
        app(StockLedger::class)->post(new StockMovementLine($this->kitchen->id, $this->chicken->id, 'sale', '-3000'));

        $this->actingAs($this->admin)->putJson("/api/inventory/counts/{$id}", ['lines' => [
            ['stock_item_id' => $this->chicken->id, 'entered_quantity' => 6500],
        ]])->assertOk();
        $res = $this->actingAs($this->admin)->postJson("/api/inventory/counts/{$id}/post")->assertOk();

        $line = collect($res->json('data.lines'))->firstWhere('stock_item_id', $this->chicken->id);
        $this->assertSame('7000.000000', $line['expected_quantity']);
        $this->assertSame('-500.000000', $line['variance_quantity']);
        $this->assertSame('6500.000000', $this->balance($this->chicken)->quantity);
    }

    public function test_posted_count_is_locked_and_several_drafts_are_allowed(): void
    {
        $id = $this->countSheet();
        // مسودة ثانية للمخزن نفسه مسموحة (عدّاد آخر) — تُدمج قبل التثبيت.
        $second = $this->countSheet();
        $this->actingAs($this->admin)->deleteJson("/api/inventory/counts/{$second}")->assertOk();

        $this->actingAs($this->admin)->postJson("/api/inventory/counts/{$id}/post")->assertStatus(422);   // لا معدود
        $this->actingAs($this->admin)->putJson("/api/inventory/counts/{$id}", ['lines' => [
            ['stock_item_id' => $this->chicken->id, 'entered_quantity' => 0],
        ]])->assertOk();
        $this->actingAs($this->admin)->postJson("/api/inventory/counts/{$id}/post")->assertOk();

        $this->actingAs($this->admin)->putJson("/api/inventory/counts/{$id}", ['lines' => []])->assertStatus(422);
        $this->actingAs($this->admin)->deleteJson("/api/inventory/counts/{$id}")->assertStatus(422);
        // بعد الترحيل يمكن فتح جرد جديد.
        $this->countSheet();
    }

    public function test_merging_drafts_sums_counts_and_overlapping_drafts_cannot_post_separately(): void
    {
        $this->seedStock($this->chicken, '30000', '0.04');
        $a = $this->countSheet();
        $b = $this->countSheet();
        $put = fn (int $id, array $lines) => $this->actingAs($this->admin)->putJson("/api/inventory/counts/{$id}", ['lines' => $lines])->assertOk();
        $put($a, [['stock_item_id' => $this->chicken->id, 'entered_quantity' => 10000]]);
        // كرتونة = 10000 غ (وحدة مختلفة عن الورقة الأولى)
        $put($b, [
            ['stock_item_id' => $this->chicken->id, 'unit_id' => $this->unit('كرتونة')->id, 'entered_quantity' => 1],
            ['stock_item_id' => $this->garlic->id, 'entered_quantity' => 500],
        ]);

        // كل ورقة وحدها كانت ستجعل الثانية تلغي الأولى في الدجاج.
        $this->actingAs($this->admin)->postJson("/api/inventory/counts/{$a}/post")->assertStatus(422);
        $this->assertSame([$b], array_column($this->actingAs($this->admin)->getJson("/api/inventory/counts/{$a}")->json('data.other_drafts'), 'id'));

        $merged = $this->actingAs($this->admin)->postJson("/api/inventory/counts/{$a}/merge", ['count_ids' => [$b]])->assertOk()->json('data');
        $lines = collect($merged['lines'])->keyBy('stock_item_id');
        $this->assertSame('20000.000000', $lines[$this->chicken->id]['counted_quantity']);
        $this->assertSame($this->chicken->base_unit_id, $lines[$this->chicken->id]['unit_id']);   // وحدتان مختلفتان ← الأساسية
        $this->assertSame('500.000000', $lines[$this->garlic->id]['counted_quantity']);
        $this->assertStringContainsString('دُمج معه', $merged['notes']);
        $this->actingAs($this->admin)->getJson("/api/inventory/counts/{$b}")->assertNotFound();

        $this->actingAs($this->admin)->postJson("/api/inventory/counts/{$a}/post")->assertOk();
        $this->assertSame('20000.000000', $this->balance($this->chicken)->quantity);

        // الدمج للمسودات فقط
        $c = $this->countSheet();
        $this->actingAs($this->admin)->postJson("/api/inventory/counts/{$c}/merge", ['count_ids' => [$a]])->assertStatus(422);
    }

    public function test_recalculate_missing_items_duplicate_and_date_filter(): void
    {
        $this->seedStock($this->chicken, '10000', '0.04');
        $id = $this->countSheet();
        app(StockLedger::class)->post(new StockMovementLine($this->kitchen->id, $this->chicken->id, 'sale', '-3000'));

        $sheet = $this->actingAs($this->admin)->postJson("/api/inventory/counts/{$id}/recalculate")->assertOk()->json('data');
        $chicken = collect($sheet['lines'])->firstWhere('stock_item_id', $this->chicken->id);
        $this->assertSame('7000.000000', $chicken['expected_quantity']);
        $this->assertSame('0.040000', $chicken['avg_cost']);

        // صنف أُنشئ بعد الورقة وله رصيد: يظهر في الفحص ويُضاف للورقة.
        $salt = $this->item('ملح', 'SALT');
        $this->seedStock($salt, '250', '0.002');
        $missing = $this->actingAs($this->admin)->getJson("/api/inventory/counts/{$id}/missing")->assertOk()->json('data');
        $this->assertSame([$salt->id], array_column($missing, 'id'));
        $added = $this->actingAs($this->admin)->postJson("/api/inventory/counts/{$id}/lines", ['stock_item_ids' => [$salt->id]])->assertOk()->json('data.lines');
        $this->assertSame('250.000000', collect($added)->firstWhere('stock_item_id', $salt->id)['expected_quantity']);
        $this->assertSame([], $this->actingAs($this->admin)->getJson("/api/inventory/counts/{$id}/missing")->json('data'));

        $copy = $this->actingAs($this->admin)->postJson("/api/inventory/counts/{$id}/duplicate")->assertCreated()->json('data');
        $this->assertSame('draft', $copy['status']);
        $this->assertCount(count($added), $copy['lines']);
        $this->assertNull(collect($copy['lines'])->firstWhere('counted_quantity', '!==', null));
        $this->assertStringContainsString('نسخة من', $copy['notes']);

        $today = $this->actingAs($this->admin)->getJson("/api/inventory/counts?from={$this->today}&to={$this->today}")->json('data');
        $this->assertEqualsCanonicalizing([$id, $copy['id']], array_column($today['data'], 'id'));
        $this->assertSame($this->today, $today['data'][0]['day']);
        $yesterday = now()->subDays(2)->toDateString();
        $this->assertSame(0, $this->actingAs($this->admin)->getJson("/api/inventory/counts?to={$yesterday}")->json('data.summary.count'));
    }

    public function test_count_requires_post_permission_and_warehouse_access(): void
    {
        $clerk = $this->user(['inventory.access', 'inventory.counts.manage']);
        $this->actingAs($clerk)->postJson('/api/inventory/counts', ['warehouse_id' => $this->kitchen->id])->assertForbidden();

        $this->kitchen->users()->attach($clerk->id, ['created_at' => now()]);
        $id = $this->actingAs($clerk)->postJson('/api/inventory/counts', ['warehouse_id' => $this->kitchen->id])->assertCreated()->json('data.id');
        $this->actingAs($clerk)->postJson("/api/inventory/counts/{$id}/post")->assertForbidden();
        $this->assertFalse($this->actingAs($clerk)->getJson("/api/inventory/counts/{$id}")->json('data.costs_visible'));
    }

    // ── ربط القسم بالمخزن ─────────────────────────────────────────────────────

    public function test_department_links_to_one_warehouse_per_branch(): void
    {
        $dept = Department::factory()->create();
        $this->actingAs($this->admin)->putJson("/api/inventory/warehouses/{$this->kitchen->id}", ['department_id' => $dept->id])
            ->assertOk();
        $this->assertSame($dept->id, $this->kitchen->fresh()->department_id);

        $this->actingAs($this->admin)->postJson('/api/inventory/warehouses', [
            'name' => 'مخزن آخر', 'code' => 'WH-OTHER', 'branch_id' => $this->kitchen->branch_id, 'department_id' => $dept->id,
        ])->assertStatus(422);
    }
}
