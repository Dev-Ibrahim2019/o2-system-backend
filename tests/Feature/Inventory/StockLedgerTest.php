<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\InventorySetting;
use App\Models\StockBalance;
use App\Models\StockItem;
use App\Models\StockItemUnit;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryRuleException;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockMovementLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** المرحلة 2: محرك المخزون — الحركات والأرصدة والمتوسط المرجّح والعكس وسجل حركة الصنف. */
class StockLedgerTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = [
        'inventory.access',
        'inventory.movements.opening',
        'inventory.movements.reverse',
        'inventory.settings.manage',
        'inventory.warehouses.all',
        'inventory.costs.view',
    ];

    private Warehouse $gaza;

    private StockItem $meat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gaza = $this->warehouse('مخزن غزة', 'WH-G');
        $this->meat = $this->item('لحمة', 'RM-MEAT');
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
        return StockUnit::where('name', $name)->firstOrFail();
    }

    private function warehouse(string $name, string $code): Warehouse
    {
        return Warehouse::create(['name' => $name, 'code' => $code, 'branch_id' => Branch::factory()->create()->id]);
    }

    /** صنف بوحدة أساسية غرام، والكيلو = 1000 غرام. */
    private function item(string $name, string $code, string $type = 'raw', bool $stored = true): StockItem
    {
        $item = StockItem::create([
            'type' => $type, 'name' => $name, 'code' => $code,
            'base_unit_id' => $this->unit('غرام')->id, 'is_stored' => $stored,
        ]);
        StockItemUnit::create(['stock_item_id' => $item->id, 'unit_id' => $this->unit('كيلوغرام')->id, 'factor' => 1000]);

        return $item;
    }

    private function ledger(): StockLedger
    {
        return app(StockLedger::class);
    }

    private function in(StockItem $item, string $grams, string $costPerGram, string $type = 'opening', array $extra = []): StockMovement
    {
        return $this->ledger()->post(new StockMovementLine(...[
            'warehouseId' => $this->gaza->id, 'stockItemId' => $item->id, 'type' => $type,
            'quantity' => $grams, 'unitCost' => $costPerGram, ...$extra,
        ]));
    }

    private function out(StockItem $item, string $grams, array $extra = [], ?bool $allowNegative = null): StockMovement
    {
        return $this->ledger()->post(new StockMovementLine(...[
            'warehouseId' => $this->gaza->id, 'stockItemId' => $item->id, 'type' => 'sale',
            'quantity' => '-'.$grams, ...$extra,
        ]), null, $allowNegative);
    }

    private function balance(StockItem $item, ?Warehouse $wh = null): ?StockBalance
    {
        return StockBalance::where('warehouse_id', ($wh ?? $this->gaza)->id)->where('stock_item_id', $item->id)->first();
    }

    // ── التسجيل والمتوسط المرجّح ───────────────────────────────────────────────

    public function test_opening_entry_converts_units_to_base_and_sets_average(): void
    {
        $admin = $this->user(self::ADMIN);

        $this->actingAs($admin)->postJson('/api/inventory/movements/opening', [
            'warehouse_id' => $this->gaza->id,
            'lines' => [['stock_item_id' => $this->meat->id, 'quantity' => 2.5, 'unit_id' => $this->unit('كيلوغرام')->id, 'unit_cost' => 40]],
        ])->assertCreated();

        $m = StockMovement::sole();
        $this->assertSame('2500.000000', $m->quantity);          // 2.5 كغ × 1000
        $this->assertSame('0.040000', $m->unit_cost);            // 40 ₪ للكيلو ÷ 1000
        $this->assertSame('100.000000', $m->total_cost);
        $this->assertSame('2.500000', $m->entered_quantity);
        $this->assertSame($this->unit('كيلوغرام')->id, $m->entered_unit_id);
        $this->assertSame($admin->id, $m->created_by);
        $this->assertNotNull($m->accounting_day);
        $this->assertNull($m->accounting_posted_at);

        $b = $this->balance($this->meat);
        $this->assertSame('2500.000000', $b->quantity);
        $this->assertSame('0.040000', $b->avg_cost);
        $this->assertSame($m->id, $b->last_movement_id);
    }

    public function test_fifo_cost_through_the_ledger(): void
    {
        // دفعتان: 10 كغ بـ 40 ثم 10 كغ بـ 50. صرف 15.75 كغ بالأقدم أولاً = 10 كغ × 40 + 5.75 كغ × 50 = 687.5 (لا متوسط)
        $this->in($this->meat, '10000', '0.04');
        $this->in($this->meat, '10000', '0.05', 'purchase');

        $b = $this->balance($this->meat);
        $this->assertSame('20000.000000', $b->quantity);
        $this->assertSame('0.045000', $b->avg_cost);   // للعرض فقط: قيمة الدفعات ÷ الكمية

        $sale = $this->out($this->meat, '15750');
        $this->assertSame('-687.500000', $sale->total_cost);
        $this->assertSame('4250.000000', $this->balance($this->meat)->quantity);
        // المتبقي من الدفعة الثانية فقط، بسعرها
        $this->assertSame('0.050000', $this->balance($this->meat)->avg_cost);
    }

    public function test_balance_after_snapshots_form_a_chain(): void
    {
        $this->in($this->meat, '1000', '0.04');
        $this->out($this->meat, '300');
        $this->in($this->meat, '500', '0.06', 'purchase');

        $this->assertSame(
            ['1000.000000', '700.000000', '1200.000000'],
            StockMovement::orderBy('id')->pluck('balance_after')->all(),
        );
    }

    // ── الرصيد السالب ─────────────────────────────────────────────────────────

    public function test_negative_balance_is_blocked_by_default_and_nothing_is_written(): void
    {
        $this->in($this->meat, '1000', '0.04');

        try {
            $this->out($this->meat, '1500');
            $this->fail('كان يجب رفض الصرف');
        } catch (InventoryRuleException $e) {
            $this->assertStringContainsString('الرصيد غير كافٍ', $e->getMessage());
        }

        $this->assertSame(1, StockMovement::count());
        $this->assertSame('1000.000000', $this->balance($this->meat)->quantity);
    }

    public function test_negative_allowed_by_setting_then_incoming_takes_its_own_cost(): void
    {
        $admin = $this->user(self::ADMIN);
        $this->actingAs($admin)->putJson('/api/inventory/settings', ['allow_negative_stock' => true])
            ->assertOk()->assertJsonPath('data.allow_negative_stock', true);

        $this->in($this->meat, '1000', '0.04');
        $this->out($this->meat, '1500');
        $this->assertSame('-500.000000', $this->balance($this->meat)->quantity);

        // القرار 4: وارد على رصيد سالب → المتوسط = تكلفة الوارد
        $this->in($this->meat, '2000', '0.05', 'purchase');
        $b = $this->balance($this->meat);
        $this->assertSame('1500.000000', $b->quantity);
        $this->assertSame('0.050000', $b->avg_cost);
    }

    public function test_caller_can_override_negative_policy(): void
    {
        // خصم البيع (المرحلة 5) لا يُمنع على الكاشير — يمرّر allowNegative صراحة.
        $this->out($this->meat, '200', [], allowNegative: true);

        $this->assertSame('-200.000000', $this->balance($this->meat)->quantity);
        $this->assertFalse(InventorySetting::current()->allow_negative_stock);
    }

    // ── الذرّية ───────────────────────────────────────────────────────────────

    public function test_post_many_is_all_or_nothing(): void
    {
        $bread = $this->item('خبز', 'RM-BREAD');

        try {
            $this->ledger()->postMany([
                new StockMovementLine($this->gaza->id, $this->meat->id, 'opening', '1000', unitCost: '0.04'),
                new StockMovementLine($this->gaza->id, $bread->id, 'sale', '-5'),
            ]);
            $this->fail('كان يجب رفض العملية');
        } catch (InventoryRuleException) {
        }

        $this->assertSame(0, StockMovement::count());
        $this->assertNull($this->balance($this->meat)?->last_movement_id);
    }

    public function test_post_many_handles_the_same_item_twice_in_one_call(): void
    {
        $this->ledger()->postMany([
            new StockMovementLine($this->gaza->id, $this->meat->id, 'opening', '1000', unitCost: '0.04'),
            new StockMovementLine($this->gaza->id, $this->meat->id, 'sale', '-400'),
        ]);

        $this->assertSame('600.000000', $this->balance($this->meat)->quantity);
    }

    // ── حماية قاعدة البيانات (Triggers) ───────────────────────────────────────

    public function test_direct_balance_update_is_rejected_by_the_database(): void
    {
        $this->in($this->meat, '1000', '0.04');

        $this->assertDatabaseRejects(fn () => DB::table('stock_balances')->update(['quantity' => 999999]));
        $this->assertDatabaseRejects(fn () => DB::table('stock_balances')->update(['avg_cost' => 1]));
        $this->assertDatabaseRejects(fn () => DB::table('stock_balances')->delete());

        $bread = $this->item('خبز', 'RM-BREAD');
        $this->assertDatabaseRejects(fn () => DB::table('stock_balances')->insert([
            'warehouse_id' => $this->gaza->id, 'stock_item_id' => $bread->id, 'quantity' => 50, 'avg_cost' => 1,
        ]));

        $this->assertSame('1000.000000', $this->balance($this->meat)->quantity);
    }

    public function test_balance_cannot_point_back_to_an_older_movement(): void
    {
        $first = $this->in($this->meat, '1000', '0.04');
        $this->out($this->meat, '300');

        $this->assertDatabaseRejects(fn () => DB::table('stock_balances')
            ->where('stock_item_id', $this->meat->id)
            ->update(['quantity' => 1000, 'avg_cost' => '0.04', 'last_movement_id' => $first->id]));
    }

    public function test_posted_movements_are_immutable(): void
    {
        $m = $this->in($this->meat, '1000', '0.04');

        $this->assertDatabaseRejects(fn () => DB::table('stock_movements')->where('id', $m->id)->update(['quantity' => 5]));
        $this->assertDatabaseRejects(fn () => DB::table('stock_movements')->where('id', $m->id)->update(['notes' => 'x']));
        $this->assertDatabaseRejects(fn () => DB::table('stock_movements')->where('id', $m->id)->delete());

        $this->expectException(LogicException::class);
        $m->update(['quantity' => 5]);
    }

    public function test_only_the_accounting_posted_flag_can_be_set(): void
    {
        $m = $this->in($this->meat, '1000', '0.04');

        $m->update(['accounting_posted_at' => now()]);

        $this->assertNotNull($m->fresh()->accounting_posted_at);
    }

    public function test_forged_movement_that_breaks_the_chain_is_rejected(): void
    {
        $this->in($this->meat, '1000', '0.04');

        $this->assertDatabaseRejects(fn () => DB::table('stock_movements')->insert([
            'warehouse_id' => $this->gaza->id, 'stock_item_id' => $this->meat->id, 'type' => 'adjustment',
            'quantity' => 10, 'unit_cost' => 0, 'total_cost' => 0,
            'balance_after' => 5000, 'avg_cost_after' => 0.04, // يجب أن يكون 1010
            'occurred_at' => now(), 'accounting_day' => now()->toDateString(),
        ]));
    }

    public function test_item_with_movements_cannot_be_deleted(): void
    {
        $this->in($this->meat, '1000', '0.04');
        $admin = $this->user(['inventory.access', 'inventory.items.manage']);

        $this->actingAs($admin)->deleteJson("/api/inventory/items/{$this->meat->id}")->assertStatus(422);
        // خط الدفاع الثاني: القيد الأجنبي في القاعدة.
        $this->assertDatabaseRejects(fn () => DB::table('stock_items')->where('id', $this->meat->id)->delete());
    }

    // ── العكس ─────────────────────────────────────────────────────────────────

    public function test_reversal_uses_the_original_cost_and_happens_once(): void
    {
        $admin = $this->user(self::ADMIN);
        $opening = $this->in($this->meat, '10000', '0.04');
        $this->in($this->meat, '10000', '0.05', 'purchase');   // 20000 @ 0.045

        $res = $this->actingAs($admin)->postJson("/api/inventory/movements/{$opening->id}/reverse", ['reason' => 'إدخال خاطئ'])
            ->assertCreated();

        $rev = StockMovement::findOrFail($res->json('data.id'));
        $this->assertSame('opening', $rev->type);
        $this->assertSame($opening->id, $rev->reversal_of_id);
        $this->assertSame('-10000.000000', $rev->quantity);
        $this->assertSame('0.040000', $rev->unit_cost);         // تكلفة الحركة الأصلية لا المتوسط 0.045
        $this->assertSame('-400.000000', $rev->total_cost);

        // يبقى 10000 بتكلفة الشراء 0.05 فقط
        $b = $this->balance($this->meat);
        $this->assertSame('10000.000000', $b->quantity);
        $this->assertSame('0.050000', $b->avg_cost);

        $this->actingAs($admin)->postJson("/api/inventory/movements/{$opening->id}/reverse", ['reason' => 'مرة ثانية'])
            ->assertStatus(422)->assertJsonPath('message', 'هذه الحركة معكوسة مسبقاً.');
        $this->actingAs($admin)->postJson("/api/inventory/movements/{$rev->id}/reverse", ['reason' => 'عكس العكس'])
            ->assertStatus(422)->assertJsonPath('message', 'لا يمكن عكس حركة عكسية.');
    }

    public function test_reversal_respects_negative_policy(): void
    {
        $admin = $this->user(self::ADMIN);
        $opening = $this->in($this->meat, '1000', '0.04');
        $this->out($this->meat, '800');

        $this->actingAs($admin)->postJson("/api/inventory/movements/{$opening->id}/reverse", ['reason' => 'خطأ'])
            ->assertStatus(422);
        $this->assertSame('200.000000', $this->balance($this->meat)->quantity);
    }

    public function test_reversing_an_outgoing_movement_returns_stock_at_its_original_cost(): void
    {
        $this->in($this->meat, '1000', '0.04');
        $sale = $this->out($this->meat, '400');
        $this->in($this->meat, '600', '0.10', 'purchase');     // 1200 @ 0.07

        $rev = $this->ledger()->reverse($sale, 'إلغاء الطلب');

        $this->assertSame('400.000000', $rev->quantity);
        $this->assertSame('0.040000', $rev->unit_cost);
        // (1200 × 0.07 + 400 × 0.04) ÷ 1600 = 0.0625
        $this->assertSame('0.062500', $this->balance($this->meat)->avg_cost);
    }

    public function test_only_opening_movements_can_be_reversed_manually(): void
    {
        $admin = $this->user(self::ADMIN);
        $this->in($this->meat, '1000', '0.04');
        $sale = $this->out($this->meat, '100');

        $this->actingAs($admin)->postJson("/api/inventory/movements/{$sale->id}/reverse", ['reason' => 'x'])
            ->assertStatus(422);
        $this->assertSame(2, StockMovement::count());
    }

    // ── منع التكرار واليوم المحاسبي والتحقق ───────────────────────────────────

    public function test_same_idempotency_key_is_posted_once(): void
    {
        $this->in($this->meat, '1000', '0.04');
        $key = 'sale:order-item:77:'.$this->meat->id;

        $first = $this->out($this->meat, '150', ['idempotencyKey' => $key]);
        $second = $this->out($this->meat, '150', ['idempotencyKey' => $key]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, StockMovement::count());
        $this->assertSame('850.000000', $this->balance($this->meat)->quantity);
    }

    public function test_movement_at_0130_belongs_to_previous_accounting_day(): void
    {
        $m = $this->in($this->meat, '1000', '0.04', 'opening', [
            'occurredAt' => CarbonImmutable::parse('2026-10-20 01:30', 'Asia/Gaza'),
        ]);

        $this->assertSame('2026-10-19', $m->fresh()->accounting_day->toDateString());
    }

    public function test_non_stored_item_cannot_move(): void
    {
        $delivery = $this->item('أجرة توصيل', 'NS-DEL', 'non_stock', stored: false);

        $this->expectException(InventoryRuleException::class);
        $this->in($delivery, '1', '1');
    }

    public function test_unit_not_defined_for_item_is_rejected(): void
    {
        $other = StockUnit::where('id', '!=', $this->unit('غرام')->id)
            ->where('id', '!=', $this->unit('كيلوغرام')->id)
            ->firstOrFail();

        $this->expectException(InventoryRuleException::class);
        $this->ledger()->post(new StockMovementLine($this->gaza->id, $this->meat->id, 'opening', '1', $other->id, '5'));
    }

    // ── سجل حركة الصنف (Kardex) ───────────────────────────────────────────────

    public function test_kardex_opening_running_balance_and_pagination(): void
    {
        $admin = $this->user(self::ADMIN);
        $at = fn (string $d) => ['occurredAt' => CarbonImmutable::parse("$d 12:00", 'Asia/Gaza')];

        $this->in($this->meat, '1000', '0.04', 'opening', $at('2026-10-01'));   // قبل الفترة
        $this->out($this->meat, '100', $at('2026-10-02'));                       // قبل الفترة
        $this->in($this->meat, '500', '0.10', 'purchase', $at('2026-10-10'));
        $this->out($this->meat, '200', $at('2026-10-11'));
        $this->out($this->meat, '50', $at('2026-10-12'));
        $this->out($this->meat, '10', $at('2026-10-25'));                        // بعد الفترة

        $q = "/api/inventory/kardex?warehouse_id={$this->gaza->id}&stock_item_id={$this->meat->id}&from=2026-10-10&to=2026-10-20";

        $res = $this->actingAs($admin)->getJson($q)->assertOk();
        $this->assertSame('900.000000', $res->json('data.opening.quantity'));
        $this->assertSame(['1400.000000', '1200.000000', '1150.000000'], array_column($res->json('data.rows'), 'running_quantity'));
        $this->assertSame('500.000000', $res->json('data.totals.quantity_in'));
        $this->assertSame('250.000000', $res->json('data.totals.quantity_out'));
        $this->assertSame('1150.000000', $res->json('data.totals.closing_quantity'));
        $this->assertSame('استلام مشتريات', $res->json('data.rows.0.type_label'));

        // الصفحة الثانية تبدأ من الرصيد الجاري الصحيح
        $page2 = $this->actingAs($admin)->getJson("$q&per_page=2&page=2")->assertOk();
        $this->assertSame(['1150.000000'], array_column($page2->json('data.rows'), 'running_quantity'));
        $this->assertSame(2, $page2->json('data.last_page'));
    }

    public function test_kardex_marks_reversible_rows(): void
    {
        $admin = $this->user(self::ADMIN);
        $opening = $this->in($this->meat, '1000', '0.04');
        $sale = $this->out($this->meat, '100');
        $url = "/api/inventory/kardex?warehouse_id={$this->gaza->id}&stock_item_id={$this->meat->id}";

        // صُرف من دفعة أول المدة: لا تُعكس حتى يُعكس الصرف.
        $rows = $this->actingAs($admin)->getJson($url)->assertOk()->json('data.rows');
        $this->assertFalse($rows[0]['can_reverse']);
        $this->assertFalse($rows[1]['can_reverse']);

        $this->ledger()->reverse($sale, 'x');
        $rows = $this->actingAs($admin)->getJson($url)->json('data.rows');
        $this->assertTrue($rows[0]['can_reverse']);

        $this->ledger()->reverse($opening, 'x', null, allowNegative: true);
        $rows = $this->actingAs($admin)->getJson($url)->json('data.rows');
        $this->assertTrue($rows[0]['is_reversed']);
        $this->assertFalse($rows[0]['can_reverse']);
    }

    // ── الصلاحيات والوصول للمخازن ──────────────────────────────────────────────

    public function test_storekeeper_cannot_reach_another_warehouse(): void
    {
        // أمين مخزن النصيرات لا يرى مخزن غزة ولا يُدخل له أرصدة.
        $nuseirat = $this->warehouse('مخزن النصيرات', 'WH-N');
        $keeper = $this->user(['inventory.access', 'inventory.movements.opening', 'inventory.movements.reverse']);
        $nuseirat->users()->attach($keeper->id, ['created_at' => now()]);

        $gazaOpening = $this->in($this->meat, '1000', '0.04');
        $this->ledger()->post(new StockMovementLine($nuseirat->id, $this->meat->id, 'opening', '700', unitCost: '0.04'));

        $this->actingAs($keeper)->getJson("/api/inventory/kardex?warehouse_id={$this->gaza->id}&stock_item_id={$this->meat->id}")
            ->assertForbidden();
        $this->actingAs($keeper)->postJson('/api/inventory/movements/opening', [
            'warehouse_id' => $this->gaza->id,
            'lines' => [['stock_item_id' => $this->meat->id, 'quantity' => 1, 'unit_cost' => 1]],
        ])->assertForbidden();
        $this->actingAs($keeper)->postJson("/api/inventory/movements/{$gazaOpening->id}/reverse", ['reason' => 'x'])
            ->assertForbidden();

        $balances = $this->actingAs($keeper)->getJson('/api/inventory/balances')->assertOk()->json('data.data');
        $this->assertSame([$nuseirat->id], array_column($balances, 'warehouse_id'));
        $this->assertSame('700.000000', $balances[0]['quantity']);

        $this->actingAs($keeper)->getJson("/api/inventory/kardex?warehouse_id={$nuseirat->id}&stock_item_id={$this->meat->id}")
            ->assertOk();
    }

    public function test_write_endpoints_require_their_permissions(): void
    {
        $viewer = $this->user(['inventory.access', 'inventory.warehouses.all']);
        $opening = $this->in($this->meat, '1000', '0.04');

        $this->actingAs($viewer)->postJson('/api/inventory/movements/opening', [
            'warehouse_id' => $this->gaza->id,
            'lines' => [['stock_item_id' => $this->meat->id, 'quantity' => 1, 'unit_cost' => 1]],
        ])->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/inventory/movements/{$opening->id}/reverse", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->putJson('/api/inventory/settings', ['allow_negative_stock' => true])->assertForbidden();
        $this->actingAs($viewer)->getJson('/api/inventory/settings')->assertOk()->assertJsonPath('data.allow_negative_stock', false);
    }

    public function test_opening_validation_messages_are_arabic(): void
    {
        $admin = $this->user(self::ADMIN);

        $this->actingAs($admin)->postJson('/api/inventory/movements/opening', [
            'warehouse_id' => $this->gaza->id,
            'lines' => [['stock_item_id' => $this->meat->id, 'quantity' => 0, 'unit_cost' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors(['lines.0.quantity' => 'حقل الكمية يجب أن يكون أكبر من صفر.']);
    }

    private function assertDatabaseRejects(callable $statement): void
    {
        try {
            $statement();
        } catch (QueryException $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'INVENTORY_LEDGER') || str_contains($e->getMessage(), 'foreign key constraint'),
                $e->getMessage(),
            );

            return;
        }
        $this->fail('كان يجب أن ترفض قاعدة البيانات هذه العملية.');
    }
}
