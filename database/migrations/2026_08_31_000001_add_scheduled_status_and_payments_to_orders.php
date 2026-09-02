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
                $table->json('payments')->nullable()->after('scheduled_at');
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
