<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'payments')) {
            Schema::table('orders', function (Blueprint $table) {
                // 'scheduled_at' is added later by
                // 2026_12_17_000001_add_tax_delivery_schedule_to_orders_table.php, so this
                // migration cannot assume it already exists yet — position after it only when
                // it does; otherwise just append the column (position has no functional effect).
                $column = $table->json('payments')->nullable();
                if (Schema::hasColumn('orders', 'scheduled_at')) {
                    $column->after('scheduled_at');
                }
            });
        }

        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        if (! Schema::hasTable('orders')) {
            return;
        }

        try {
            DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_check');
        } catch (\Throwable $e) {
            // Constraint may not exist
        }
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending', 'pending_confirmation', 'confirmed', 'in_progress', 'ready', 'served', 'paid', 'cancelled', 'pending_payment', 'scheduled', 'PENDING_PAYMENT', 'PREPARATION', 'ASSEMBLING', 'READY_FOR_DELIVERY', 'OUT_FOR_DELIVERY', 'CANCELLATION_REQUESTED', 'DELIVERED', 'FAILED_DELIVERY', 'CANCELLED'))");
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'payments')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('payments');
            });
        }

        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        if (! Schema::hasTable('orders')) {
            return;
        }

        try {
            DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_check');
        } catch (\Throwable $e) {
            // Constraint may not exist
        }
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending', 'pending_confirmation', 'confirmed', 'in_progress', 'ready', 'served', 'paid', 'cancelled', 'pending_payment', 'PENDING_PAYMENT', 'PREPARATION', 'ASSEMBLING', 'READY_FOR_DELIVERY', 'OUT_FOR_DELIVERY', 'CANCELLATION_REQUESTED', 'DELIVERED', 'FAILED_DELIVERY', 'CANCELLED'))");
    }
};
