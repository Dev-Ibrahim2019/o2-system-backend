<?php

namespace App\Services\Inventory;

use App\Models\DepartmentBranchSetting;
use App\Models\InventorySetting;
use App\Models\StockBatch;
use App\Models\StockCount;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * قواعد قسم الشاورما (من O2):
 *  - اللحوم المستهلكة في اليوم = عجز جرد اليوم لأصناف تصنيف اللحوم (+ أي صرف/بيع مسجّل)، بلا الإتلاف.
 *  - الأسياخ: دفعات من صنف السيخ برقم تسلسلي عام؛ تبقى في مخزن القسم حتى "تُصدَّر" (تخرج منه).
 *  - البهار المفترض = اللحوم المستهلكة (كغ) × غرامات البهار لكل كغ (إعداد؛ الافتراضي 30 = 1.5 لكل 50 كغ).
 *  - الغلة = إيراد القسم اليومي من نقطة البيع ÷ وزن الشاورما الصادرة (كغ).
 * كل الأوزان بالوحدة الأساسية (غرام)، وتُعرض بالكيلو.
 */
class ShawarmaService
{
    /** حركات استهلاك القسم: تسوية الجرد والبيع وصرف الإنتاج (الإتلاف مستثنى — لا يُحسب عجزاً). */
    private const CONSUMED = ['adjustment', 'sale', 'production_out'];

    public function __construct(private StockLedger $ledger, private ?AccountingDay $accountingDay = null)
    {
        $this->accountingDay ??= AccountingDay::fromConfig();
    }

    /** إعداد الشاورما لمخزن القسم، أو خطأ واضح إن لم يُضبط. */
    public function settingsFor(Warehouse $warehouse): DepartmentBranchSetting
    {
        $s = DepartmentBranchSetting::for($warehouse->department_id, $warehouse->branch_id);
        if (! $s?->is_shawarma || ! $s->meat_category_id) {
            throw new InventoryRuleException('هذا المخزن ليس مخزن قسم شاورما مضبوطاً (القسم، تصنيف اللحوم، صنف السيخ).');
        }

        return $s;
    }

    /** @return array<int> أصناف تصنيف اللحوم */
    public function meatItems(DepartmentBranchSetting $s): array
    {
        return DB::table('stock_category_item')->where('stock_category_id', $s->meat_category_id)->pluck('stock_item_id')->map(fn ($i) => (int) $i)->all();
    }

    /**
     * اللحوم المستهلكة في يوم (بالوحدة الأساسية)، أو null إن لم يُرحَّل جرد ذلك اليوم بعد
     * (فالمستهلك غير معروف).
     */
    public function meatConsumed(Warehouse $warehouse, DepartmentBranchSetting $s, string $day): ?string
    {
        $counted = StockCount::where('warehouse_id', $warehouse->id)->where('kind', 'daily')
            ->where('business_day', $day)->where('status', 'posted')->exists();
        if (! $counted) {
            return null;
        }

        return $this->consumed($warehouse->id, $this->meatItems($s), $day);
    }

    /** @return array{count: int, weight: string} أسياخ اليوم المنتجة (مستندات مرحّلة غير معكوسة) */
    public function skewersProduced(Warehouse $warehouse, DepartmentBranchSetting $s, string $day): array
    {
        $row = DB::table('stock_documents as d')
            ->join('stock_document_lines as l', 'l.stock_document_id', '=', 'd.id')
            ->where('d.type', StockDocument::TYPE_SKEWER)->where('d.status', StockDocument::STATUS_POSTED)
            ->where('d.warehouse_id', $warehouse->id)->where('d.document_date', $day)
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(b.quantity), 0) as w')
            ->join('stock_batches as b', 'b.id', '=', 'l.created_batch_id')
            ->first();

        return ['count' => (int) $row->c, 'weight' => (string) $row->w];
    }

    /** متوسط تكلفة اللحوم في مخزن القسم (لكل غرام) — تكلفة السيخ المنتج. */
    public function meatAverageCost(Warehouse $warehouse, DepartmentBranchSetting $s): string
    {
        $v = DB::table('stock_balances')->where('warehouse_id', $warehouse->id)
            ->whereIn('stock_item_id', $this->meatItems($s) ?: [0])->where('quantity', '>', 0)
            ->selectRaw('SUM(quantity * avg_cost) / SUM(quantity) as c')->value('c');

        return UnitConverter::round((string) ($v ?? '0'));
    }

    /** تصدير سيخ: يخرج كامل رصيده من مخزن القسم (لا يُصدَّر مرتين). */
    public function exportSkewer(StockBatch $batch, User $by): StockMovement
    {
        if ($batch->source !== 'skewer') {
            throw new InventoryRuleException('هذه الدفعة ليست سيخ شاورما.');
        }
        $balance = DB::table('stock_batch_balances')->where('batch_id', $batch->id)->where('quantity', '>', 0)->first();
        if (! $balance) {
            throw new InventoryRuleException("السيخ {$batch->code} صُدِّر مسبقاً.");
        }

        return $this->ledger->post(new StockMovementLine(
            warehouseId: (int) $balance->warehouse_id,
            stockItemId: $batch->stock_item_id,
            type: 'production_out',
            quantity: bcmul((string) $balance->quantity, '-1', 6),
            sourceType: 'skewer_export',
            sourceId: $batch->id,
            idempotencyKey: 'skewer-export:'.$batch->id,
            notes: "تصدير السيخ {$batch->code}",
            batchId: $batch->id,
        ), $by, allowNegative: false);
    }

    /**
     * تقرير يوم لقسم الشاورما.
     *
     * @return array<string, mixed>
     */
    public function day(Warehouse $warehouse, string $day): array
    {
        $s = $this->settingsFor($warehouse);
        $meatIds = $this->meatItems($s);
        $meat = $this->meatConsumed($warehouse, $s, $day);
        $skewers = $this->skewersProduced($warehouse, $s, $day);

        // الشاورما الصادرة = Σ أوزان الأسياخ التي خرجت من مخزن القسم في اليوم
        $exported = $s->skewer_item_id ? bcmul((string) DB::table('stock_movements')
            ->where('warehouse_id', $warehouse->id)->where('stock_item_id', $s->skewer_item_id)
            ->where('type', 'production_out')->where('accounting_day', $day)->sum('quantity'), '-1', 6) : '0';

        [$start, $end] = $this->accountingDay->bounds($day);
        // الإيراد = مبيعات القسم المدفوعة في فرع المخزن خلال اليوم المحاسبي (من نقطة البيع)
        $revenue = (string) DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.payment_status', 'paid')->whereNull('o.deleted_at')->where('oi.status', '!=', 'cancelled')
            ->where('o.branch_id', $warehouse->branch_id)->where('oi.department_id', $warehouse->department_id)
            ->where('o.paid_at', '>=', $start->toDateTimeString())->where('o.paid_at', '<', $end->toDateTimeString())
            ->sum('oi.total');

        $exportedKg = bcdiv($exported, '1000', 6);
        // الغلة = الإيراد ÷ وزن الشاورما الصادرة بالكيلو
        $yield = bccomp($exportedKg, '0', 6) > 0 ? bcdiv($revenue, $exportedKg, 2) : null;

        $spiceTheory = null;
        $spiceActual = null;
        if ($s->spice_item_id && $meat !== null) {
            // البهار المفترض (غ) = اللحوم المستهلكة (كغ) × غرامات البهار لكل كغ
            $spiceTheory = bcmul(bcdiv($meat, '1000', 6), (string) InventorySetting::current()->spice_grams_per_kg, 6);
            $spiceActual = $this->consumed($warehouse->id, [(int) $s->spice_item_id], $day);
        }

        // تفصيل الباقي من اللحوم من جرد اليوم: بدون تشريح = الباقي − مشرّح − مبهّر
        $details = DB::table('stock_count_lines as l')->join('stock_counts as c', 'c.id', '=', 'l.stock_count_id')
            ->join('stock_items as i', 'i.id', '=', 'l.stock_item_id')
            ->where('c.warehouse_id', $warehouse->id)->where('c.kind', 'daily')->where('c.business_day', $day)
            ->whereIn('l.stock_item_id', $meatIds ?: [0])
            ->get(['i.name', 'l.counted_quantity', 'l.dissected_quantity', 'l.marinated_quantity'])
            ->map(fn ($r) => [
                'name' => $r->name,
                'remaining' => (string) ($r->counted_quantity ?? '0'),
                'dissected' => (string) ($r->dissected_quantity ?? '0'),
                'marinated' => (string) ($r->marinated_quantity ?? '0'),
                'undissected' => bcsub(bcsub((string) ($r->counted_quantity ?? '0'), (string) ($r->dissected_quantity ?? '0'), 6), (string) ($r->marinated_quantity ?? '0'), 6),
            ])->all();

        return [
            'day' => $day,
            'meat_consumed' => $meat,
            'meat_details' => $details,
            'skewers_count' => $skewers['count'],
            'skewers_weight' => $skewers['weight'],
            // شرط O2: أوزان الأسياخ ≤ اللحوم المستهلكة
            'skewers_exceed_meat' => $meat !== null && bccomp($skewers['weight'], $meat, 6) > 0,
            'exported_weight' => $exported,
            'revenue' => number_format((float) $revenue, 2, '.', ''),
            'yield_per_kg' => $yield,
            'spice_theoretical' => $spiceTheory,
            'spice_actual' => $spiceActual,
            'spice_deficit' => $spiceTheory !== null ? bcsub($spiceActual, $spiceTheory, 6) : null,
            'counted' => $meat !== null,
        ];
    }

    /**
     * تقرير فترة: سلسلة الأيام وإجمالياتها وأفضل وأضعف يوم (بالغلة).
     *
     * @return array<string, mixed>
     */
    public function period(Warehouse $warehouse, string $from, string $to): array
    {
        $days = [];
        for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $to; $d = $d->addDay()) {
            $days[] = $this->day($warehouse, $d->toDateString());
        }
        $sum = fn (string $k) => array_reduce($days, fn ($c, $r) => bcadd($c, (string) ($r[$k] ?? '0'), 6), '0');
        $withYield = array_values(array_filter($days, fn ($r) => $r['yield_per_kg'] !== null));
        usort($withYield, fn ($a, $b) => (float) $b['yield_per_kg'] <=> (float) $a['yield_per_kg']);
        $exportedKg = bcdiv($sum('exported_weight'), '1000', 6);

        return [
            'days' => $days,
            'totals' => [
                'skewers_count' => array_sum(array_column($days, 'skewers_count')),
                'skewers_weight' => $sum('skewers_weight'),
                'exported_weight' => $sum('exported_weight'),
                'meat_consumed' => $sum('meat_consumed'),
                'revenue' => number_format((float) $sum('revenue'), 2, '.', ''),
                'yield_per_kg' => bccomp($exportedKg, '0', 6) > 0 ? bcdiv($sum('revenue'), $exportedKg, 2) : null,
                'spice_theoretical' => $sum('spice_theoretical'),
                'spice_actual' => $sum('spice_actual'),
                'spice_deficit' => bcsub($sum('spice_actual'), $sum('spice_theoretical'), 6),
            ],
            'best_day' => $withYield[0] ?? null,
            'worst_day' => $withYield !== [] ? end($withYield) : null,
        ];
    }

    /** استهلاك أصناف في مخزن خلال يوم = −Σ حركات الاستهلاك (بلا الإتلاف). */
    private function consumed(int $warehouseId, array $itemIds, string $day): string
    {
        return bcmul((string) DB::table('stock_movements')
            ->where('warehouse_id', $warehouseId)->whereIn('stock_item_id', $itemIds ?: [0])
            ->whereIn('type', self::CONSUMED)->where('accounting_day', $day)
            ->sum('quantity'), '-1', 6);
    }
}
