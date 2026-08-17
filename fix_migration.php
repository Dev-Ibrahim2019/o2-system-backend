<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

// 1. إنشاء delivery_trips إذا لم يكن موجوداً
if (!Schema::hasTable('delivery_trips')) {
    Schema::create('delivery_trips', function (Blueprint $table) {
        $table->id();
        $table->foreignId('branch_id')->constrained()->restrictOnDelete();
        $table->foreignId('driver_id')->nullable()->constrained('employees')->restrictOnDelete();
        $table->string('number')->unique();
        $table->string('status', 20)->default('draft');
        $table->text('notes')->nullable();
        $table->timestamp('started_at')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamps();
        $table->index(['branch_id', 'status']);
    });
    echo "delivery_trips created\n";
} else {
    echo "delivery_trips already exists\n";
}

// 2. إنشاء delivery_trip_stops إذا لم يكن موجوداً
if (!Schema::hasTable('delivery_trip_stops')) {
    Schema::create('delivery_trip_stops', function (Blueprint $table) {
        $table->id();
        $table->foreignId('delivery_trip_id')->constrained()->cascadeOnDelete();
        $table->foreignId('order_id')->constrained()->restrictOnDelete();
        $table->unsignedSmallInteger('sequence');
        $table->string('status', 20)->default('pending');
        $table->json('delivery_address_snapshot')->nullable();
        $table->timestamp('arrived_at')->nullable();
        $table->timestamp('delivered_at')->nullable();
        $table->text('notes')->nullable();
        $table->text('failure_reason')->nullable();
        $table->timestamps();
        $table->unique(['delivery_trip_id', 'order_id']);
        $table->unique(['delivery_trip_id', 'sequence']);
    });
    echo "delivery_trip_stops created\n";
} else {
    echo "delivery_trip_stops already exists\n";
}

// 3. إضافة delivery_fee إلى orders إذا لم يكن موجوداً
if (!Schema::hasColumn('orders', 'delivery_fee')) {
    Schema::table('orders', function (Blueprint $table) {
        $table->decimal('delivery_fee', 12, 3)->nullable()->default(0)->after('engine_discount_amount');
    });
    echo "delivery_fee added to orders\n";
} else {
    echo "delivery_fee already exists in orders\n";
}

// 4. تسجيل المايجريشن كمنفذ
$migrationName = '2027_01_01_000001_create_delivery_management_tables';
$exists = DB::table('migrations')->where('migration', $migrationName)->exists();
if (!$exists) {
    $batch = DB::table('migrations')->max('batch') ?? 0;
    DB::table('migrations')->insert([
        'migration' => $migrationName,
        'batch' => $batch + 1,
    ]);
    echo "Migration $migrationName registered as completed\n";
} else {
    echo "Migration $migrationName already registered\n";
}

echo "\nAll fixes applied successfully!\n";