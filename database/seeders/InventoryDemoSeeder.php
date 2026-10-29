<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\Recipe;
use App\Models\StockBalance;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockItemUnit;
use App\Models\StockUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\AccountingDay;
use App\Services\Inventory\InventoryAnalyticsService;
use App\Services\Inventory\StockCountService;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockMovementLine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * بيانات تجريبية لتحليلات المخزون (للتطوير فقط — لا تُشغَّل على الإنتاج):
 *   php artisan db:seed --class=InventoryDemoSeeder
 *
 * - أصناف خام ووسيط (ثومية) بتصنيفات ووحدات (غرام / كغ / كرتونة / حبة).
 * - وصفات لأصناف منيو موجودة اسمها يحتوي "شاورما" (لا تُنشأ أصناف منيو).
 * - رصيد أول المدة قبل 14 يوماً في المخزن المرتبط بقسم (أو أول مخزن عادي).
 * - مبيعات مدفوعة يومية لـ 14 يوماً (order_number يبدأ بـ DEMO-INV) ثم جرد اليوم بعجز ~4% عن النظري.
 * آمن للتكرار: الأصناف بالكود، والوصفة والرصيد والمبيعات لا تُعاد إن وُجدت.
 */
class InventoryDemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->orderBy('id')->firstOrFail();
        $unit = fn (string $name, ?string $symbol = null) => StockUnit::firstOrCreate(['name' => $name], ['symbol' => $symbol, 'is_active' => true]);
        $gram = $unit('غرام', 'g');
        $kg = $unit('كيلوغرام', 'kg');
        $carton = $unit('كرتونة');
        $piece = $unit('حبة');

        $poultry = StockCategory::firstOrCreate(['code' => 'DEMO-POULTRY'], ['name' => 'دجاج', 'is_active' => true]);
        $veg = StockCategory::firstOrCreate(['code' => 'DEMO-VEG'], ['name' => 'خضار وصلصات', 'is_active' => true]);
        $bakery = StockCategory::firstOrCreate(['code' => 'DEMO-BAKERY'], ['name' => 'مخبوزات', 'is_active' => true]);

        $item = function (string $code, string $name, string $type, StockUnit $base, array $alts, StockCategory $cat, float $safety = 0) {
            $i = StockItem::firstOrCreate(['code' => $code], [
                'type' => $type, 'name' => $name, 'base_unit_id' => $base->id, 'is_stored' => true, 'safety_stock' => $safety,
            ]);
            foreach ($alts as [$u, $factor]) {
                StockItemUnit::firstOrCreate(['stock_item_id' => $i->id, 'unit_id' => $u->id], ['factor' => $factor]);
            }
            $i->categories()->syncWithoutDetaching([$cat->id]);

            return $i;
        };
        $breast = $item('DEMO-CHK-BREAST', 'صدر دجاج', 'raw', $gram, [[$kg, 1000], [$carton, 10000]], $poultry, 15000);
        $thigh = $item('DEMO-CHK-THIGH', 'فخد دجاج', 'raw', $gram, [[$kg, 1000], [$carton, 10000]], $poultry, 10000);
        $garlic = $item('DEMO-GARLIC', 'ثوم', 'raw', $gram, [[$kg, 1000]], $veg);
        $oil = $item('DEMO-OIL', 'زيت', 'raw', $gram, [[$kg, 1000]], $veg);
        $bread = $item('DEMO-BREAD', 'خبز صاج', 'raw', $piece, [], $bakery, 100);
        $sauce = $item('DEMO-SAUCE', 'ثومية', 'intermediate', $gram, [[$kg, 1000]], $veg);

        $today = AccountingDay::fromConfig()->of(now());
        $start = now()->subDays(14)->toDateString();
        // وصفة بتاريخ سابق تُنشأ مباشرة (الواجهة لا تسمح بتاريخ سابق) حتى تشمل المبيعات التجريبية.
        $recipe = function (?int $itemId, ?int $stockItemId, float $yield, array $lines) use ($start) {
            if (Recipe::where($itemId ? 'item_id' : 'stock_item_id', $itemId ?: $stockItemId)->exists()) {
                return;
            }
            $r = Recipe::create(['item_id' => $itemId, 'stock_item_id' => $stockItemId, 'effective_from' => $start, 'yield_quantity' => $yield]);
            foreach ($lines as [$stock, $qty]) {
                $r->lines()->create(['stock_item_id' => $stock->id, 'unit_id' => $stock->base_unit_id, 'quantity' => $qty, 'base_quantity' => $qty]);
            }
        };
        $recipe(null, $sauce->id, 1000, [[$garlic, 200], [$oil, 800]]);

        $menu = Item::where('name_ar', 'like', '%شاورما%')->orWhere('name', 'like', '%shawarma%')->orderBy('id')->limit(3)->get();
        $grams = [120, 180, 150];
        foreach ($menu->values() as $i => $m) {
            $recipe($m->id, null, 1, [[$i === 2 ? $thigh : $breast, $grams[$i]], [$sauce, 30], [$bread, 1]]);
        }
        $this->command?->info($menu->isEmpty()
            ? 'لا أصناف منيو باسم "شاورما" — أُنشئت الأصناف والوصفة الفرعية فقط.'
            : 'وصفات لـ: '.$menu->map(fn ($m) => $m->name_ar ?: $m->name)->implode('، '));

        $warehouse = Warehouse::whereNotNull('department_id')->where('is_active', true)->first()
            ?? Warehouse::where('type', 'standard')->where('is_active', true)->firstOrFail();
        $this->command?->info("المخزن: {$warehouse->name}");

        $hasStock = StockBalance::where('warehouse_id', $warehouse->id)->where('stock_item_id', $breast->id)->whereNotNull('last_movement_id')->exists();
        if (! $hasStock) {
            $at = now()->subDays(14);
            app(StockLedger::class)->postMany([
                new StockMovementLine($warehouse->id, $breast->id, 'opening', '40', $kg->id, '38', $at),
                new StockMovementLine($warehouse->id, $thigh->id, 'opening', '25', $kg->id, '28', $at),
                new StockMovementLine($warehouse->id, $garlic->id, 'opening', '5', $kg->id, '12', $at),
                new StockMovementLine($warehouse->id, $oil->id, 'opening', '10', $kg->id, '9', $at),
                new StockMovementLine($warehouse->id, $bread->id, 'opening', '600', null, '0.5', $at),
            ], $user, allowNegative: true);
        }

        if ($menu->isEmpty() || ! $warehouse->branch_id || DB::table('orders')->where('order_number', 'like', 'DEMO-INV-%')->exists()) {
            return;
        }
        foreach (range(13, 0) as $d) {
            foreach ($menu->values() as $i => $m) {
                $qty = [14, 5, 4][$i] + ($d % 4);
                $paidAt = now()->subDays($d)->setTime(18, 30);
                $orderId = DB::table('orders')->insertGetId([
                    'order_number' => "DEMO-INV-{$d}-{$m->id}", 'branch_id' => $warehouse->branch_id, 'status' => 'closed',
                    'payment_status' => 'paid', 'paid_at' => $paidAt, 'created_at' => $paidAt, 'updated_at' => $paidAt,
                ]);
                DB::table('order_items')->insert([
                    'order_id' => $orderId, 'item_id' => $m->id, 'department_id' => $warehouse->department_id ?? $m->department_id,
                    'item_name' => $m->name, 'item_name_ar' => $m->name_ar, 'quantity' => $qty, 'price' => 12, 'total' => 12 * $qty,
                    'status' => 'served', 'created_at' => $paidAt,
                ]);
            }
        }

        // جرد اليوم: الرصيد المسجّل − الاستهلاك النظري للفترة × 1.04 (عجز تجريبي ~4%؛ خصم المبيعات التلقائي غير مبني بعد).
        $rows = app(InventoryAnalyticsService::class)->variance([
            'from' => $start, 'to' => $today, 'days' => 15, 'warehouses' => [$warehouse->id], 'items' => null,
            'threshold' => '3', 'stagnant_days' => 30, 'stock_item_id' => null,
        ])['rows'];
        $counts = app(StockCountService::class);
        $sheet = $counts->create($warehouse, null, 'جرد تجريبي', $user);
        $lines = [];
        foreach ($rows as $row) {
            $recorded = (float) StockBalance::where('warehouse_id', $warehouse->id)->where('stock_item_id', $row['stock_item_id'])->value('quantity');
            $lines[] = ['stock_item_id' => $row['stock_item_id'], 'entered_quantity' => max(0, round($recorded - (float) $row['theoretical_qty'] * 1.04, 2))];
        }
        $counts->saveCounts($sheet, $lines, 'جرد تجريبي');
        $counts->post($sheet, $user);
        $this->command?->info('أُضيفت مبيعات 14 يوماً وجرد اليوم.');
    }
}
