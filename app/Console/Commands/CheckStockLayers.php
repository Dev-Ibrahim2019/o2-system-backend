<?php

namespace App\Console\Commands;

use App\Services\Inventory\StockLayerReportService;
use Illuminate\Console\Command;

/** فحص مطابقة الدفعات: الرصيد = المتبقي − النقص المعلّق، وقيمة الدفتر = قيمة الدفعات، لكل صنف في كل مخزن. */
class CheckStockLayers extends Command
{
    protected $signature = 'inventory:check-layers';

    protected $description = 'فحص مطابقة الدفعات مع أرصدة المخزون وقيمتها';

    public function handle(StockLayerReportService $reports): int
    {
        $result = $reports->reconcile();
        if ($result['mismatches'] === []) {
            $this->info("مطابق: {$result['checked']} صنف/مخزن.");

            return self::SUCCESS;
        }
        $this->table(['المخزن', 'الصنف', 'الرصيد', 'كمية الدفعات', 'قيمة الدفتر', 'قيمة الدفعات'],
            array_map(fn ($m) => [$m['warehouse'], $m['name'], $m['balance_quantity'], $m['layer_quantity'], $m['ledger_value'], $m['layer_value']], $result['mismatches']));

        return self::FAILURE;
    }
}
