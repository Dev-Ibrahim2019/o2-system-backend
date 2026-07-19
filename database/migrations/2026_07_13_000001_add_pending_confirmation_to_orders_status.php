<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders')) return;

        // حذف القيد القديم وإعادة إنشائه مع pending_confirmation
        try {
            DB::statement("ALTER TABLE orders DROP CHECK orders_status_check");
        } catch (\Throwable $e) {
            // Constraint may not exist
        }
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending', 'pending_confirmation', 'confirmed', 'in_progress', 'ready', 'served', 'paid', 'cancelled'))");
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) return;

        try {
            DB::statement("ALTER TABLE orders DROP CHECK orders_status_check");
        } catch (\Throwable $e) {
            // Constraint may not exist
        }
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending', 'confirmed', 'in_progress', 'ready', 'served', 'paid', 'cancelled'))");
    }
};
