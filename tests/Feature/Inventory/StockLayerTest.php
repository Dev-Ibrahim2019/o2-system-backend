<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\StockItem;
use App\Models\StockItemUnit;
use App\Models\StockLayer;
use App\Models\StockLayerAllocation;
use App\Models\StockLayerShortage;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryRuleException;
use App\Services\Inventory\StockLayerReportService;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockMovementLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * تكلفة المخزون بالدفعات على مستوى محرك الحركات (اختبارات المواصفة 1، 2، 3، 7، 9، 10).
 * الكميات بالغرام (الوحدة الأساسية) والأسعار لكل غرام؛ الكيلو = 1000 غرام.
 */
class StockLayerTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $store;

    private StockItem $meat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Warehouse::create(['name' => 'المخزن الرئيسي', 'code' => 'WH-L', 'branch_id' => Branch::factory()->create()->id]);
        $this->meat = StockItem::create(['type' => 'raw', 'name' => 'لحمة', 'code' => 'RM-L', 'base_unit_id' => $this->unit('غرام')->id, 'is_stored' => true]);
        StockItemUnit::create(['stock_item_id' => $this->meat->id, 'unit_id' => $this->unit('كيلوغرام')->id, 'factor' => 1000]);
    }

    private function unit(string $name): StockUnit
    {
        return StockUnit::firstOrCreate(['name' => $name], ['is_active' => true]);
    }

    private function ledger(): StockLedger
    {
        return app(StockLedger::class);
    }

    private function in(string $grams, string $cost, string $type = 'purchase'): StockMovement
    {
        return $this->ledger()->post(new StockMovementLine($this->store->id, $this->meat->id, $type, $grams, unitCost: $cost));
    }

    private function out(string $grams, ?array $allocations = null, string $type = 'waste', ?bool $allowNegative = null): StockMovement
    {
        return $this->ledger()->post(new StockMovementLine($this->store->id, $this->meat->id, $type, '-'.$grams, allocations: $allocations), null, $allowNegative);
    }

    private function layer(StockMovement $incoming): StockLayer
    {
        return StockLayer::where('movement_id', $incoming->id)->firstOrFail();
    }

    private function reconcile(): array
    {
        return app(StockLayerReportService::class)->reconcile()['mismatches'];
    }

    public function test_1_manual_issue_from_the_second_layer_takes_its_price(): void
    {
        $this->in('10000', '0.04');
        $second = $this->layer($this->in('10000', '0.05'));

        $issue = $this->out('2000', [['layer_id' => $second->id, 'quantity' => '2000']]);

        $this->assertSame('-100.000000', $issue->total_cost);   // 2 كغ × 50
        $this->assertSame('8000.000000', $second->fresh()->qty_remaining);
        $this->assertSame(1, StockLayerAllocation::where('movement_id', $issue->id)->where('layer_id', $second->id)->count());
    }

    public function test_2_fifo_takes_from_two_layers_with_mixed_cost(): void
    {
        $first = $this->layer($this->in('3000', '0.04'));
        $second = $this->layer($this->in('5000', '0.05'));

        $issue = $this->out('5000');

        // 3 كغ × 40 + 2 كغ × 50 = 220
        $this->assertSame('-220.000000', $issue->total_cost);
        $this->assertSame('0.000000', $first->fresh()->qty_remaining);
        $this->assertSame('3000.000000', $second->fresh()->qty_remaining);
        $this->assertSame([['3000.000000', '0.040000'], ['2000.000000', '0.050000']],
            StockLayerAllocation::where('movement_id', $issue->id)->orderBy('id')->get()->map(fn ($a) => [$a->quantity, $a->unit_cost])->all());
    }

    public function test_3_manual_issue_beyond_layer_remaining_is_rejected_and_writes_nothing(): void
    {
        $layer = $this->layer($this->in('1000', '0.04'));
        $this->in('5000', '0.05');
        $movements = StockMovement::count();

        foreach ([
            [[['layer_id' => $layer->id, 'quantity' => '1500']], '1500', 'أكبر من المتبقي'],   // يتجاوز المتبقي في الدفعة
            [[['layer_id' => $layer->id, 'quantity' => '500']], '800', 'لا يساوي الكمية'],    // التوزيع لا يساوي الكمية
        ] as [$allocations, $qty, $message]) {
            try {
                $this->out($qty, $allocations);
                $this->fail('كان يجب رفض الصرف');
            } catch (InventoryRuleException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        // وبلا دفعات كافية (الرصيد السالب ممنوع افتراضياً)
        $this->expectException(InventoryRuleException::class);
        try {
            $this->out('7000', null, 'waste', false);
        } finally {
            $this->assertSame($movements, StockMovement::count());
            $this->assertSame('1000.000000', $layer->fresh()->qty_remaining);
        }
    }

    public function test_7_shortage_is_provisional_then_settled_by_the_next_layer(): void
    {
        $this->in('1000', '0.04');
        // بيع 1.5 كغ والمتاح 1 كغ: النقص 0.5 كغ بسعر آخر دفعة (40) مؤقتاً — البيع لا يتوقف.
        $sale = $this->out('1500', null, 'sale', true);
        $this->assertSame('-60.000000', $sale->total_cost);
        $this->assertSame('500.000000', StockLayerShortage::where('movement_id', $sale->id)->value('qty_open'));
        $this->assertSame('-500.000000', DB::table('stock_balances')->where('stock_item_id', $this->meat->id)->value('quantity'));

        // وصول دفعة بسعر 60: تُسوّى الـ 0.5 كغ منها أولاً، والفرق (0.5 × 20 = 10) يُصحَّح على حركة البيع نفسها.
        $incoming = $this->in('2000', '0.06');
        $this->assertSame(StockLayerShortage::SETTLED, StockLayerShortage::where('movement_id', $sale->id)->value('status'));
        $this->assertSame('1500.000000', $this->layer($incoming)->fresh()->qty_remaining);
        $fix = StockMovement::where('adjusts_movement_id', $sale->id)->firstOrFail();
        $this->assertSame(['sale', '0.000000', '-10.000000'], [$fix->type, $fix->quantity, $fix->total_cost]);
        // تكلفة البيع الفعلية = 1 كغ × 40 + 0.5 كغ × 60 = 70
        $this->assertEquals(-70, (float) StockMovement::where('id', $sale->id)->orWhere('adjusts_movement_id', $sale->id)->sum('total_cost'));
        $this->assertSame([], $this->reconcile());
    }

    public function test_9_reversing_an_issue_returns_quantities_to_the_same_layers(): void
    {
        $first = $this->layer($this->in('3000', '0.04'));
        $second = $this->layer($this->in('5000', '0.05'));
        $issue = $this->out('5000');

        $reversal = $this->ledger()->reverse($issue, 'خطأ');

        $this->assertSame('220.000000', $reversal->total_cost);
        $this->assertSame('3000.000000', $first->fresh()->qty_remaining);
        $this->assertSame('5000.000000', $second->fresh()->qty_remaining);
        // التخصيص الأصلي باقٍ، والعكس سطور عكسية مربوطة به.
        $this->assertSame(2, StockLayerAllocation::where('movement_id', $issue->id)->count());
        $this->assertSame(2, StockLayerAllocation::where('movement_id', $reversal->id)->where('kind', 'restore')->whereNotNull('reverses_allocation_id')->count());
        $this->assertSame([], $this->reconcile());
    }

    public function test_reversing_an_incoming_movement_requires_its_layer_untouched(): void
    {
        $incoming = $this->in('1000', '0.04', 'opening');
        $this->out('100');

        $this->expectException(InventoryRuleException::class);
        $this->expectExceptionMessage('صُرف 100');
        $this->ledger()->reverse($incoming, 'x');
    }

    public function test_10_valuation_equals_remaining_times_cost_and_reconciles(): void
    {
        $this->in('3000', '0.04');
        $this->in('5000', '0.05');
        $this->out('4000');
        $this->out('500', null, 'sale', true);

        // المتبقي: 4000 غ من الثانية × 0.05 − 500 غ = 3500 غ × 0.05 = 175
        $value = (float) StockLayer::where('stock_item_id', $this->meat->id)->selectRaw('SUM(qty_remaining * unit_cost) v')->value('v');
        $this->assertEquals(175, $value);
        $this->assertEquals(175, (float) DB::table('stock_movements')->where('stock_item_id', $this->meat->id)->sum('total_cost'));
        $this->assertSame('3500.000000', DB::table('stock_balances')->where('stock_item_id', $this->meat->id)->value('quantity'));
        $this->assertSame([], $this->reconcile());
    }

    public function test_layer_quantity_can_never_go_negative_in_the_database(): void
    {
        $layer = $this->layer($this->in('1000', '0.04'));

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('stock_layers')->where('id', $layer->id)->update(['qty_remaining' => -1]);
    }

    public function test_allocations_are_immutable(): void
    {
        $this->in('1000', '0.04');
        $issue = $this->out('100');

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('stock_layer_allocations')->where('movement_id', $issue->id)->delete();
    }
}
