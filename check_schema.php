<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== ORDERS TABLE COLUMNS ===\n";
$cols = DB::select('SHOW COLUMNS FROM orders');
foreach ($cols as $c) {
    echo $c->Field . ' | ' . str_pad($c->Type, 30) . ' | ' . ($c->Null === 'YES' ? 'NULL' : 'NOT NULL') . ' | Default: ' . ($c->Default ?? 'NULL') . "\n";
}

echo "\n=== TABLES IN DATABASE ===\n";
$tables = DB::select('SHOW TABLES');
foreach ($tables as $t) {
    $tableName = reset((array)$t);
    if (strpos($tableName, 'customer') !== false || strpos($tableName, 'delivery') !== false || strpos($tableName, 'supplier') !== false || strpos($tableName, 'employee') !== false || strpos($tableName, 'user') !== false) {
        echo "*** " . $tableName . " ***\n";
    }
}

echo "\n=== STATUS ENUM VALUES ===\n";
$statusCol = DB::select("SHOW COLUMNS FROM orders WHERE Field = 'status'");
if (!empty($statusCol)) {
    $type = $statusCol[0]->Type;
    echo $type . "\n";
}

echo "\n=== ORDER_TYPE ENUM VALUES ===\n";
$typeCol = DB::select("SHOW COLUMNS FROM orders WHERE Field = 'order_type'");
if (!empty($typeCol)) {
    echo $typeCol[0]->Type . "\n";
}

echo "\n=== SOURCE ENUM VALUES ===\n";
$sourceCol = DB::select("SHOW COLUMNS FROM orders WHERE Field = 'source'");
if (!empty($sourceCol)) {
    echo $sourceCol[0]->Type . "\n";
} else {
    echo "Column 'source' does NOT exist\n";
}

echo "\n=== PAYMENT_STATUS ENUM VALUES ===\n";
$payCol = DB::select("SHOW COLUMNS FROM orders WHERE Field = 'payment_status'");
if (!empty($payCol)) {
    echo $payCol[0]->Type . "\n";
} else {
    echo "Column 'payment_status' does NOT exist\n";
}

echo "\n=== DELIVERY_ZONE TABLE ===\n";
$dzCheck = DB::select("SHOW TABLES LIKE 'delivery_zones'");
if (!empty($dzCheck) || DB::select("SHOW TABLES LIKE 'delivery_zone'")) {
    $tbl = (DB::select("SHOW TABLES LIKE 'delivery_zones'}")) ? 'delivery_zones' : 'delivery_zone';
    echo "Delivery zones table exists\n";
} else {
    echo "No delivery_zones table found\n";
}

echo "\n=== CUSTOMER_ADDRESSES TABLE ===\n";
$caCheck = DB::select("SHOW TABLES LIKE 'customer_addresses'");
if (!empty($caCheck)) {
    echo "Customer addresses table exists\n";
    $caCols = DB::select('SHOW COLUMNS FROM customer_addresses');
    foreach ($caCols as $c) {
        echo "  - " . $c->Field . " (" . $c->Type . ")\n";
    }
} else {
    echo "No customer_addresses table found\n";
}