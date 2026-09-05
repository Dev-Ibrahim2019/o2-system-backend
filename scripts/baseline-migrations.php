<?php

/**
 * baseline-migrations.php
 * ---------------------------------------------------------------------------
 * Reconciles the `migrations` table with the real database schema.
 *
 * Problem it solves: migration files were renamed/rewritten over time, so
 * Laravel sees them as "pending" and tries to CREATE tables that already
 * exist -> "SQLSTATE[42S01] ... Table 'orders' already exists".
 *
 * What it does: for every migration file that is NOT yet recorded in the
 * `migrations` table, if the table it creates ALREADY exists in the DB, it
 * inserts a row marking that migration as run (without executing it).
 * Migrations whose tables do NOT exist are left untouched and will run
 * normally on the next `php artisan migrate`.
 *
 * It never drops, alters, or truncates anything. Only INSERTs into `migrations`.
 *
 * Usage (run from the project root on the server):
 *   php scripts/baseline-migrations.php            # dry run, shows the plan
 *   php scripts/baseline-migrations.php --apply    # actually writes the rows
 * ---------------------------------------------------------------------------
 */

$apply = in_array('--apply', $argv, true);

require __DIR__ . '/../vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

// create_permission_tables etc. don't follow the create_<name>_table pattern.
$specialCases = [
    'create_permission_tables' => 'permissions',
];

if (! Schema::hasTable('migrations')) {
    fwrite(STDERR, "No `migrations` table found. Run `php artisan migrate:install` first.\n");
    exit(1);
}

$batch    = (int) DB::table('migrations')->max('batch');
$recorded = DB::table('migrations')->pluck('migration')->flip();

$toBaseline = [];   // already-existing tables -> mark as run
$willRun    = [];   // genuinely pending -> leave for `artisan migrate`
$unknown    = [];   // couldn't determine a table name

foreach (File::files(__DIR__ . '/../database/migrations') as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $name = $file->getBasename('.php');

    if ($recorded->has($name)) {
        continue;
    }

    $table = null;

    foreach ($specialCases as $needle => $checkTable) {
        if (str_contains($name, $needle)) {
            $table = $checkTable;
            break;
        }
    }

    if ($table === null && preg_match('/create_(.+?)_table$/', $name, $m)) {
        $table = $m[1];
    }

    if ($table === null) {
        // Not a "create table" migration (add column / alter / data fix).
        // If it isn't recorded it is genuinely pending; make it idempotent
        // in code if it might have already been applied under this name.
        $willRun[] = $name;
        continue;
    }

    if (Schema::hasTable($table)) {
        $toBaseline[] = ['migration' => $name, 'table' => $table];
    } else {
        $willRun[] = $name;
    }
}

echo "\n=== BASELINE (table already exists -> mark migration as run) ===\n";
if (! $toBaseline) {
    echo "  (nothing)\n";
}
foreach ($toBaseline as $row) {
    echo "  + {$row['migration']}   [table: {$row['table']}]\n";
}

echo "\n=== WILL RUN on next `php artisan migrate` (review these!) ===\n";
if (! $willRun) {
    echo "  (nothing)\n";
}
foreach ($willRun as $name) {
    echo "  > {$name}\n";
}

if (! $apply) {
    echo "\nDry run only. Re-run with --apply to write the baseline rows.\n";
    exit(0);
}

if (! $toBaseline) {
    echo "\nNothing to baseline. Done.\n";
    exit(0);
}

$nextBatch = $batch + 1;

DB::transaction(function () use ($toBaseline, $nextBatch) {
    foreach ($toBaseline as $row) {
        DB::table('migrations')->insert([
            'migration' => $row['migration'],
            'batch'     => $nextBatch,
        ]);
    }
});

echo "\nDone. Baselined " . count($toBaseline) . " migration(s) as batch {$nextBatch}.\n";
echo "Now run:  php artisan migrate --force\n";
