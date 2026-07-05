<?php
define('LARAVEL_START', microtime(true));
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Migrations table ===\n";
$migrations = DB::table('migrations')->orderBy('batch')->orderBy('migration')->get();
foreach ($migrations as $m) {
    echo sprintf("batch=%d %s\n", $m->batch, $m->migration);
}

echo "\n=== Tables in database ===\n";
$tables = DB::select('SHOW TABLES');
foreach ($tables as $t) {
    $vals = array_values((array)$t);
    echo $vals[0] . "\n";
}
