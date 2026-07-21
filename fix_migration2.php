<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

$applied = [];

// 1. call_tickets
if (!Schema::hasTable('call_tickets')) {
    Schema::create('call_tickets', function (Blueprint $table) {
        $table->id(); $table->string('external_call_id')->nullable()->unique();
        $table->foreignId('branch_id')->constrained()->restrictOnDelete();
        $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
        $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('linked_order_id')->nullable()->constrained('orders')->nullOnDelete();
        $table->string('direction',16)->default('inbound'); $table->string('status',24)->default('ringing');
        $table->string('incoming_phone',40); $table->string('normalized_phone',24);
        $table->string('source')->nullable(); $table->string('disposition')->nullable(); $table->text('notes')->nullable();
        $table->timestamp('started_at')->nullable(); $table->timestamp('answered_at')->nullable(); $table->timestamp('ended_at')->nullable();
        $table->unsignedInteger('duration_seconds')->nullable(); $table->json('metadata')->nullable(); $table->timestamps();
        $table->index('normalized_phone'); $table->index('customer_id'); $table->index('agent_id');
        $table->index(['branch_id','status']); $table->index('started_at');
    });
    $applied[] = 'call_tickets';
}

// 2. idempotency_records
if (!Schema::hasTable('idempotency_records')) {
    Schema::create('idempotency_records', function (Blueprint $table) {
        $table->id(); $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        $table->string('scope',80); $table->uuid('key'); $table->char('request_hash',64);
        $table->string('resource_type')->nullable(); $table->unsignedBigInteger('resource_id')->nullable();
        $table->json('response')->nullable(); $table->unsignedSmallInteger('response_status')->default(200); $table->timestamps();
        $table->unique(['scope','key']);
    });
    $applied[] = 'idempotency_records';
}

// 3. order_cancellation_requests
if (!Schema::hasTable('order_cancellation_requests')) {
    Schema::create('order_cancellation_requests', function (Blueprint $table) {
        $table->id(); $table->foreignId('order_id')->constrained()->restrictOnDelete();
        $table->foreignId('delivery_trip_id')->nullable()->constrained()->nullOnDelete();
        $table->foreignId('delivery_trip_stop_id')->nullable()->constrained()->nullOnDelete();
        $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
        $table->string('source',24); $table->string('reason_code',40); $table->text('reason_text')->nullable();
        $table->boolean('customer_confirmed')->default(false); $table->string('status',24)->default('pending');
        $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamp('reviewed_at')->nullable(); $table->text('resolution_note')->nullable(); $table->timestamps();
        $table->index(['order_id','status']);
    });
    $applied[] = 'order_cancellation_requests';
}

// 4. إضافة الأعمدة إلى delivery_trips
if (Schema::hasTable('delivery_trips')) {
    if (!Schema::hasColumn('delivery_trips', 'assigned_by')) {
        Schema::table('delivery_trips', function (Blueprint $table) {
            $table->foreignId('assigned_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });
        $applied[] = 'delivery_trips.assigned_by';
    }
    if (!Schema::hasColumn('delivery_trips', 'max_stops')) {
        Schema::table('delivery_trips', function (Blueprint $table) {
            $table->unsignedTinyInteger('max_stops')->default(3);
        });
        $applied[] = 'delivery_trips.max_stops';
    }
    if (!Schema::hasColumn('delivery_trips', 'cancelled_at')) {
        Schema::table('delivery_trips', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable(); $table->text('cancellation_reason')->nullable();
        });
        $applied[] = 'delivery_trips.cancelled_at';
    }
}

// 5. إضافة الأعمدة إلى delivery_trip_stops
if (Schema::hasTable('delivery_trip_stops')) {
    $tripStopColumns = Schema::getColumnListing('delivery_trip_stops');
    $columnsToAdd = [];
    if (!in_array('failed_at', $tripStopColumns)) $columnsToAdd[] = 'failed_at';
    if (!in_array('cancelled_at', $tripStopColumns)) $columnsToAdd[] = 'cancelled_at';
    if (!in_array('cancellation_reason', $tripStopColumns)) $columnsToAdd[] = 'cancellation_reason';
    if (!empty($columnsToAdd)) {
        Schema::table('delivery_trip_stops', function (Blueprint $table) use ($columnsToAdd) {
            if (in_array('failed_at', $columnsToAdd)) $table->timestamp('failed_at')->nullable();
            if (in_array('cancelled_at', $columnsToAdd)) $table->timestamp('cancelled_at')->nullable();
            if (in_array('cancellation_reason', $columnsToAdd)) $table->text('cancellation_reason')->nullable();
        });
        $applied[] = 'delivery_trip_stops columns';
    }
}

// 6. items.is_weight_based
if (!Schema::hasColumn('items', 'is_weight_based')) {
    Schema::table('items', fn(Blueprint $table) => $table->boolean('is_weight_based')->default(false));
    $applied[] = 'items.is_weight_based';
}

// 7. order_items.weight_grams
if (!Schema::hasColumn('order_items', 'weight_grams')) {
    Schema::table('order_items', fn(Blueprint $table) => $table->unsignedInteger('weight_grams')->nullable());
    $applied[] = 'order_items.weight_grams';
}

// 8. orders.manual_adjustment, adjustment_reason, adjusted_by, adjusted_at
if (!Schema::hasColumn('orders', 'manual_adjustment')) {
    Schema::table('orders', function(Blueprint $table){
        $table->decimal('manual_adjustment',12,3)->default(0); $table->text('adjustment_reason')->nullable();
        $table->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('adjusted_at')->nullable();
    });
    $applied[] = 'orders.manual_adjustment fields';
}

// 9. تسجيل المايجريشن
$migrationName = '2027_01_02_000001_complete_call_center_delivery_workflow';
$exists = DB::table('migrations')->where('migration', $migrationName)->exists();
if (!$exists) {
    $batch = DB::table('migrations')->max('batch') ?? 0;
    DB::table('migrations')->insert([
        'migration' => $migrationName,
        'batch' => $batch + 1,
    ]);
    $applied[] = "Migration registered";
}

if (empty($applied)) {
    echo "Everything is already up to date.\n";
} else {
    echo "Applied: " . implode(', ', $applied) . "\n";
}
echo "Done.\n";