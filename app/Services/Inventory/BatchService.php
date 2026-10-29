<?php

namespace App\Services\Inventory;

use App\Models\InventorySetting;
use App\Models\StockBatch;
use App\Models\StockItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * الدفعات: إنشاؤها بكود تلقائي، وتسلسل الأسياخ العام، وتوزيع العجز على الدفعات (الأقرب انتهاءً أولاً)،
 * وحساب المتبقي ومدة التخزين وحالة الصلاحية.
 */
class BatchService
{
    /**
     * دفعة جديدة لصنف. كودها = كود الصنف + التسلسل التالي للصنف (X1 → X11، X12، X13...).
     * يُستدعى داخل معاملة الترحيل؛ قفل سطر الصنف يمنع تسلسلين متطابقين لعمليتين متزامنتين.
     *
     * @param  array<string, mixed>  $attrs
     */
    public function create(StockItem $item, string $source, array $attrs, ?string $code = null): StockBatch
    {
        DB::table('stock_items')->where('id', $item->id)->lockForUpdate()->value('id');
        $sequence = (int) StockBatch::where('stock_item_id', $item->id)->max('sequence') + 1;

        return StockBatch::create([
            ...$attrs,
            'stock_item_id' => $item->id,
            'source' => $source,
            'sequence' => $sequence,
            'code' => $code ?? $item->code.$sequence,
        ]);
    }

    /** الرقم التالي لعدّاد عام لا يتكرر أبداً (أسياخ الشاورما)، حتى لو حُذف ما قبله. */
    public function nextSerial(string $name): int
    {
        DB::table('serial_counters')->insertOrIgnore(['name' => $name, 'value' => 0]);
        $current = (int) DB::table('serial_counters')->where('name', $name)->lockForUpdate()->value('value');
        DB::table('serial_counters')->where('name', $name)->update(['value' => $current + 1]);

        return $current + 1;
    }

    /**
     * توزيع كمية صادرة على دفعات الصنف في المخزن: الأقرب انتهاءً أولاً، ثم الأقدم.
     * ما يزيد عن مجموع أرصدة الدفعات يُرجَع بلا دفعة (مخزون قديم قبل تتبع الدفعات).
     *
     * @return array<int, array{batch_id: ?int, quantity: string}>  كميات موجبة
     */
    public function allocate(int $warehouseId, int $stockItemId, string $quantity): array
    {
        $left = $quantity;
        $parts = [];
        $rows = DB::table('stock_batch_balances as bb')
            ->join('stock_batches as b', 'b.id', '=', 'bb.batch_id')
            ->where('bb.warehouse_id', $warehouseId)
            ->where('b.stock_item_id', $stockItemId)
            ->where('bb.quantity', '>', 0)
            ->orderByRaw('b.expiry_date IS NULL, b.expiry_date')
            ->orderBy('b.id')
            ->get(['bb.batch_id', 'bb.quantity']);
        foreach ($rows as $r) {
            if (bccomp($left, '0', UnitConverter::SCALE) <= 0) {
                break;
            }
            $take = bccomp((string) $r->quantity, $left, UnitConverter::SCALE) < 0 ? (string) $r->quantity : $left;
            $parts[] = ['batch_id' => (int) $r->batch_id, 'quantity' => $take];
            $left = bcsub($left, $take, UnitConverter::SCALE);
        }
        if (bccomp($left, '0', UnitConverter::SCALE) > 0) {
            $parts[] = ['batch_id' => null, 'quantity' => $left];
        }

        return $parts;
    }

    /** رصيد الصنف في المخزن غير الموزّع على دفعات (مخزون قبل تتبع الدفعات). */
    public function unbatched(int $warehouseId, int $stockItemId): string
    {
        $total = (string) (DB::table('stock_balances')->where('warehouse_id', $warehouseId)->where('stock_item_id', $stockItemId)->value('quantity') ?? '0');
        $batched = (string) DB::table('stock_batch_balances as bb')
            ->join('stock_batches as b', 'b.id', '=', 'bb.batch_id')
            ->where('bb.warehouse_id', $warehouseId)->where('b.stock_item_id', $stockItemId)
            ->sum('bb.quantity');

        return bcsub($total, $batched, UnitConverter::SCALE);
    }

    /**
     * حالة الصلاحية حسب الإعدادات: expired | critical (≤ 30 يوماً) | warning (≤ 90 يوماً) | ok | none.
     *
     * @return array{days_to_expiry: ?int, status: string}
     */
    public static function expiryStatus(?string $expiryDate, ?string $today = null): array
    {
        if (! $expiryDate) {
            return ['days_to_expiry' => null, 'status' => 'none'];
        }
        $s = InventorySetting::current();
        $today ??= AccountingDay::fromConfig()->of(now());
        // المدة المتبقية = تاريخ الانتهاء − اليوم (سالبة = منتهية)
        $days = (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($expiryDate), false);

        return [
            'days_to_expiry' => $days,
            'status' => match (true) {
                $days < 0 => 'expired',
                $days <= $s->expiry_critical_days => 'critical',
                $days <= $s->expiry_warning_days => 'warning',
                default => 'ok',
            },
        ];
    }
}
