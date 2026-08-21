<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        try {
            DB::statement("ALTER TABLE orders DROP CONSTRAINT orders_status_check");
        } catch (\Exception $e) {
            // Ignore
        }

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN (
            'pending', 'pending_confirmation', 'confirmed', 'in_progress',
            'ready', 'served', 'paid', 'cancelled', 'pending_payment',
            'PENDING_PAYMENT', 'PREPARATION', 'ASSEMBLING',
            'READY_FOR_DELIVERY', 'OUT_FOR_DELIVERY', 'CANCELLATION_REQUESTED',
            'DELIVERED', 'FAILED_DELIVERY', 'CANCELLED',
            'PREPARATION', 'READY', 'SERVED'
        ))");
    }

    public function down(): void
    {
        try {
            DB::statement("ALTER TABLE orders DROP CONSTRAINT orders_status_check");
        } catch (\Exception $e) {
            // Ignore
        }

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN (
            'pending', 'pending_confirmation', 'confirmed', 'in_progress',
            'ready', 'served', 'paid', 'cancelled', 'pending_payment'
        ))");
    }
};
