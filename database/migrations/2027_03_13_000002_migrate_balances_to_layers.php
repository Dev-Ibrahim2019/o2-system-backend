<?php

use App\Services\Inventory\AccountingDay;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ترحيل الأرصدة الحالية إلى الدفعات (مرة واحدة):
 *
 * - كل رصيد موجب ← دفعة "رصيد أول المدة" بكميته وتكلفته الحالية، بتاريخ الترحيل.
 * - كل رصيد سالب ← نقص معلّق بتكلفة مؤقتة = تكلفته الحالية (يُسوّى من أول دفعة تصل).
 * - إن اختلفت قيمة الرصيد (الكمية × التكلفة) عن مجموع قيم حركاته في الدفتر — بقايا المتوسط المرجّح بعد رصيد
 *   سالب — تُسجَّل حركة "إعادة تقييم" بالفرق (قيمة فقط)، فيتطابق كرت الصنف مع الدفعات.
 * - التحقق: لكل صنف في كل مخزن، الكمية والقيمة تطابقان الرصيد؛ وإلا يُلغى الترحيل كله.
 *
 * العكس يحذف الدفعات والنواقص التي أنشأها الترحيل. حركات إعادة التقييم تبقى (الدفتر لا يُحذف منه) وقيمتها متسقة.
 */
return new class extends Migration
{
    private const NOTE = 'ترحيل الأرصدة إلى الدفعات';

    public function up(): void
    {
        $balances = DB::table('stock_balances')->where('quantity', '!=', 0)->orderBy('warehouse_id')->orderBy('stock_item_id')->get();
        if ($balances->isEmpty()) {
            return;
        }
        $now = now();
        $day = AccountingDay::fromConfig()->of($now);

        DB::transaction(function () use ($balances, $now, $day) {
            foreach ($balances as $b) {
                $qty = (string) $b->quantity;
                $avg = (string) $b->avg_cost;
                $value = bcmul($qty, $avg, 6);

                if (bccomp($qty, '0', 6) > 0) {
                    DB::table('stock_layers')->insert([
                        'warehouse_id' => $b->warehouse_id, 'stock_item_id' => $b->stock_item_id,
                        'source_type' => 'opening_balance', 'received_at' => $now,
                        'qty_in' => $qty, 'qty_remaining' => $qty, 'unit_cost' => $avg,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                } else {
                    $last = DB::table('stock_movements')->where('id', $b->last_movement_id)->first(['id', 'type']);
                    DB::table('stock_layer_shortages')->insert([
                        'warehouse_id' => $b->warehouse_id, 'stock_item_id' => $b->stock_item_id,
                        'movement_id' => $last?->id, 'movement_type' => $last?->type ?? 'adjustment',
                        'quantity' => bcmul($qty, '-1', 6), 'qty_open' => bcmul($qty, '-1', 6),
                        'provisional_unit_cost' => $avg, 'status' => 'open', 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }

                // محاذاة الدفتر: مجموع قيم الحركات يجب أن يساوي قيمة الدفعات.
                $ledger = (string) DB::table('stock_movements')->where('warehouse_id', $b->warehouse_id)
                    ->where('stock_item_id', $b->stock_item_id)->sum('total_cost');
                $diff = bcsub($value, $ledger, 6);
                if (bccomp($diff, '0', 6) !== 0) {
                    $id = DB::table('stock_movements')->insertGetId([
                        'warehouse_id' => $b->warehouse_id, 'stock_item_id' => $b->stock_item_id, 'type' => 'revaluation',
                        'quantity' => 0, 'unit_cost' => 0, 'total_cost' => $diff,
                        'balance_after' => $qty, 'avg_cost_after' => $avg,
                        'occurred_at' => $now, 'accounting_day' => $day, 'notes' => self::NOTE, 'created_at' => $now,
                    ]);
                    DB::table('stock_balances')->where('id', $b->id)->update(['last_movement_id' => $id, 'updated_at' => $now]);
                }
            }

            $this->verify();
        });
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS stock_layers_bd');
        DB::table('stock_layer_shortages')->whereNull('movement_line_id')->where('status', 'open')->delete();
        DB::table('stock_layers')->whereNull('movement_id')->where('source_type', 'opening_balance')
            ->whereNotExists(fn ($q) => $q->from('stock_layer_allocations as a')->whereColumn('a.layer_id', 'stock_layers.id'))->delete();
        DB::unprepared("CREATE TRIGGER stock_layers_bd BEFORE DELETE ON stock_layers FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVENTORY_LAYERS: layers cannot be deleted'; END");
    }

    /** الكمية والقيمة من الدفعات والنواقص = الرصيد وقيمة حركاته، لكل صنف في كل مخزن. */
    private function verify(): void
    {
        $bad = DB::select(<<<'SQL'
            SELECT b.warehouse_id, b.stock_item_id, b.quantity,
                   COALESCE(l.qty, 0) - COALESCE(s.qty, 0) AS layer_qty,
                   COALESCE(l.val, 0) - COALESCE(s.val, 0) AS layer_value,
                   COALESCE(m.val, 0) AS ledger_value
            FROM stock_balances b
            LEFT JOIN (SELECT warehouse_id, stock_item_id, SUM(qty_remaining) qty, SUM(qty_remaining * unit_cost) val
                       FROM stock_layers GROUP BY warehouse_id, stock_item_id) l
                   ON l.warehouse_id = b.warehouse_id AND l.stock_item_id = b.stock_item_id
            LEFT JOIN (SELECT warehouse_id, stock_item_id, SUM(qty_open) qty, SUM(qty_open * provisional_unit_cost) val
                       FROM stock_layer_shortages WHERE status = 'open' GROUP BY warehouse_id, stock_item_id) s
                   ON s.warehouse_id = b.warehouse_id AND s.stock_item_id = b.stock_item_id
            LEFT JOIN (SELECT warehouse_id, stock_item_id, SUM(total_cost) val FROM stock_movements GROUP BY warehouse_id, stock_item_id) m
                   ON m.warehouse_id = b.warehouse_id AND m.stock_item_id = b.stock_item_id
            HAVING ABS(b.quantity - layer_qty) > 0.000001 OR ABS(layer_value - ledger_value) > 0.0001
        SQL);
        if ($bad !== []) {
            throw new RuntimeException('ترحيل الدفعات لم يطابق الأرصدة: '.json_encode($bad, JSON_UNESCAPED_UNICODE));
        }
    }
};
