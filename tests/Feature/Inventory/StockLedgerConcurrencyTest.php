<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\StockItem;
use App\Models\StockUnit;
use App\Models\Warehouse;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockMovementLine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * التزامن الحقيقي (القاعدة 3): عدة عمليات PHP مستقلة تصرف من الصنفين نفسيهما في اللحظة نفسها.
 *
 * لا يستخدم RefreshDatabase: العمّال اتصالات منفصلة لا ترى بيانات معاملة غير مؤكَّدة، فتُثبَّت
 * البيانات فعلاً ثم تُحذف في tearDown. العامل يرفض العمل خارج قاعدة الاختبار.
 */
class StockLedgerConcurrencyTest extends TestCase
{
    private const WORKERS = 4;

    private const ITERATIONS = 50;

    private const START = 1000;

    /** @var array{branch?: int, warehouse?: int, items?: array<int>} */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('o2_restaurant_test', config('database.connections.mysql.database'));
        $this->artisan('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->created !== []) {
            // TRUNCATE لا يطلق Triggers الحذف — مقبول هنا لأنها قاعدة الاختبار وبياناتها من هذا الاختبار فقط.
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            DB::table('stock_balances')->truncate();
            foreach (['stock_layer_revaluations', 'stock_layer_allocations', 'stock_layer_shortages', 'stock_draft_allocations', 'stock_layers'] as $table) {
                DB::table($table)->truncate();
            }
            DB::table('stock_movements')->truncate();
            DB::table('stock_item_units')->whereIn('stock_item_id', $this->created['items'] ?? [])->delete();
            DB::table('stock_items')->whereIn('id', $this->created['items'] ?? [])->delete();
            DB::table('warehouses')->where('id', $this->created['warehouse'] ?? 0)->delete();
            DB::table('branches')->where('id', $this->created['branch'] ?? 0)->delete();
            DB::table('audit_logs')
                ->whereIn('auditable_type', [StockItem::class, Warehouse::class])
                ->delete();
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
        parent::tearDown();
    }

    public function test_parallel_withdrawals_lose_nothing_and_never_deadlock(): void
    {
        $gram = StockUnit::where('name', 'غرام')->firstOrFail();
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::create(['name' => 'مخزن تزامن', 'code' => 'WH-CONC', 'branch_id' => $branch->id]);
        $a = StockItem::create(['type' => 'raw', 'name' => 'خبز', 'code' => 'CONC-A', 'base_unit_id' => $gram->id, 'is_stored' => true]);
        $b = StockItem::create(['type' => 'raw', 'name' => 'لحمة', 'code' => 'CONC-B', 'base_unit_id' => $gram->id, 'is_stored' => true]);
        $this->created = ['branch' => $branch->id, 'warehouse' => $warehouse->id, 'items' => [$a->id, $b->id]];

        app(StockLedger::class)->postMany([
            new StockMovementLine($warehouse->id, $a->id, 'opening', (string) self::START, unitCost: '1'),
            new StockMovementLine($warehouse->id, $b->id, 'opening', (string) self::START, unitCost: '2'),
        ]);

        // نصف العمّال يرسل الأسطر بترتيب (أ، ب) والنصف الآخر (ب، أ): ترتيب الأقفال الثابت يمنع الـ Deadlock.
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'o2_restaurant_test',
            'CACHE_STORE' => 'array',
            'TELESCOPE_ENABLED' => 'false',
            'PULSE_ENABLED' => 'false',
            'NIGHTWATCH_ENABLED' => 'false',
        ]);
        $processes = [];
        for ($w = 0; $w < self::WORKERS; $w++) {
            $cmd = [PHP_BINARY, base_path('tests/Support/stock_ledger_worker.php'),
                (string) $warehouse->id, (string) $a->id, (string) $b->id, (string) self::ITERATIONS, $w % 2 ? 'ba' : 'ab'];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $this->assertIsResource($proc);
            $processes[] = [$proc, $pipes];
        }

        foreach ($processes as [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            $this->assertSame(0, $code, "worker failed:\n{$out}\n{$err}");
        }

        $expected = self::START - self::WORKERS * self::ITERATIONS;   // 1000 − 4 × 50 = 800
        foreach ([$a, $b] as $item) {
            $balance = DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->where('stock_item_id', $item->id)->first();
            $this->assertEquals($expected, (float) $balance->quantity, "رصيد {$item->name}");
            // الدفعة الوحيدة (أول المدة) نقصت بالضبط ما صُرف، ومجموع التخصيصات = المصروف.
            $this->assertEquals($expected, (float) DB::table('stock_layers')->where('stock_item_id', $item->id)->sum('qty_remaining'));
            $this->assertEquals(self::WORKERS * self::ITERATIONS, (float) DB::table('stock_layer_allocations')
                ->join('stock_layers as l', 'l.id', '=', 'stock_layer_allocations.layer_id')->where('l.stock_item_id', $item->id)->sum('quantity'));

            // كل صرف رأى الرصيد بعد سابقه: لقطات الرصيد بعد الحركة 999..800 كلٌّ منها مرة واحدة بالضبط.
            $snapshots = DB::table('stock_movements')
                ->where('stock_item_id', $item->id)->where('type', 'sale')
                ->orderBy('balance_after', 'desc')
                ->pluck('balance_after')
                ->map(fn ($v) => (int) round((float) $v))
                ->all();
            $this->assertSame(range(self::START - 1, $expected), $snapshots);
        }
    }

    /** اختبار الدفعات 4: عمليتا صرف متزامنتان من الدفعة نفسها (60 + 60 من 100) — تنجح واحدة فقط، والمتبقي لا يصبح سالباً. */
    public function test_two_concurrent_issues_from_the_same_layer_never_overdraw(): void
    {
        $gram = StockUnit::where('name', 'غرام')->firstOrFail();
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::create(['name' => 'مخزن دفعات', 'code' => 'WH-LAYER', 'branch_id' => $branch->id]);
        $item = StockItem::create(['type' => 'raw', 'name' => 'زيت', 'code' => 'CONC-L', 'base_unit_id' => $gram->id, 'is_stored' => true]);
        $this->created = ['branch' => $branch->id, 'warehouse' => $warehouse->id, 'items' => [$item->id]];
        app(StockLedger::class)->post(new StockMovementLine($warehouse->id, $item->id, 'opening', '100', unitCost: '2'));
        $layerId = (int) DB::table('stock_layers')->where('stock_item_id', $item->id)->value('id');

        $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'o2_restaurant_test',
            'CACHE_STORE' => 'array', 'TELESCOPE_ENABLED' => 'false', 'PULSE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false']);
        $processes = [];
        for ($w = 0; $w < 2; $w++) {
            $cmd = [PHP_BINARY, base_path('tests/Support/stock_layer_worker.php'), (string) $warehouse->id, (string) $item->id, (string) $layerId, '60'];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $processes[] = [$proc, $pipes];
        }
        $outcomes = [];
        foreach ($processes as [$proc, $pipes]) {
            $outcomes[] = trim(stream_get_contents($pipes[1]));
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($proc), $err);
        }
        sort($outcomes);

        $this->assertSame(['ok', 'rejected'], $outcomes);
        $this->assertSame('40.000000', DB::table('stock_layers')->where('id', $layerId)->value('qty_remaining'));
        $this->assertSame('40.000000', DB::table('stock_balances')->where('stock_item_id', $item->id)->value('quantity'));
    }
}
