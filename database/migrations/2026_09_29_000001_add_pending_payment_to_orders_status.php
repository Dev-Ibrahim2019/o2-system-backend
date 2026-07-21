<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        try {
            DB::statement("ALTER TABLE orders DROP CHECK orders_status_check");
        } catch (\Exception $e) {
            // Ignore if constraint doesn't exist
        }

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending', 'pending_confirmation', 'confirmed', 'in_progress', 'ready', 'served', 'paid', 'cancelled', 'pending_payment'))");
    }

    public function down(): void
    {
        try {
            DB::statement("ALTER TABLE orders DROP CHECK orders_status_check");
        } catch (\Exception $e) {
            // Ignore if constraint doesn't exist
        }

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending', 'pending_confirmation', 'confirmed', 'in_progress', 'ready', 'served', 'paid', 'cancelled'))");
    }
};
