<?php

/**
 * عامل اختبار التزامن (StockLedgerConcurrencyTest): عملية PHP مستقلة تسجّل حركات صادرة
 * عبر StockLedger على قاعدة الاختبار، بالتوازي مع عمّال آخرين.
 *
 * الاستخدام: php stock_ledger_worker.php <warehouseId> <itemA> <itemB> <iterations> <ab|ba>
 * كل تكرار يصرف 1 من الصنفين معاً في عملية واحدة؛ "ba" يعكس ترتيب الأسطر لاختبار ترتيب الأقفال.
 */

use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockMovementLine;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $warehouseId, $itemA, $itemB, $iterations, $order] = $argv;

if (config('database.connections.mysql.database') !== 'o2_restaurant_test') {
    fwrite(STDERR, "refusing to run outside the test database\n");
    exit(2);
}

$ledger = $app->make(StockLedger::class);
$lines = fn () => [
    new StockMovementLine((int) $warehouseId, (int) $itemA, 'sale', '-1'),
    new StockMovementLine((int) $warehouseId, (int) $itemB, 'sale', '-1'),
];

try {
    for ($i = 0; $i < (int) $iterations; $i++) {
        $batch = $lines();
        $ledger->postMany($order === 'ba' ? array_reverse($batch) : $batch);
    }
} catch (Throwable $e) {
    // معالج أخطاء Laravel يعرض الخطأ ويُنهي بالرمز 0؛ الاختبار يحتاج رمز فشل صريحاً.
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}

echo "done\n";
