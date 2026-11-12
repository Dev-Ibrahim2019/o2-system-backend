<?php

/**
 * عامل اختبار تزامن الدفعات (StockLayerConcurrencyTest): عملية مستقلة تصرف كمية محددة من دفعة بعينها
 * (تحديد معيّن) بالتوازي مع عامل آخر على الدفعة نفسها. تطبع ok أو rejected وتنتهي بالرمز 0 في الحالتين.
 *
 * الاستخدام: php stock_layer_worker.php <warehouseId> <itemId> <layerId> <quantity>
 */

use App\Services\Inventory\InventoryRuleException;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockMovementLine;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $warehouseId, $itemId, $layerId, $quantity] = $argv;

if (config('database.connections.mysql.database') !== 'o2_restaurant_test') {
    fwrite(STDERR, "refusing to run outside the test database\n");
    exit(2);
}

try {
    $app->make(StockLedger::class)->post(new StockMovementLine((int) $warehouseId, (int) $itemId, 'waste', '-'.$quantity,
        allocations: [['layer_id' => (int) $layerId, 'quantity' => $quantity]]), null, false);
    echo "ok\n";
} catch (InventoryRuleException $e) {
    echo "rejected\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
