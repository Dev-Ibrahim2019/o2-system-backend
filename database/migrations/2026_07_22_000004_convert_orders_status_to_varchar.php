<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE orders MODIFY COLUMN `status` VARCHAR(50) DEFAULT 'PENDING_PAYMENT'");

        try {
            DB::statement("ALTER TABLE orders DROP CHECK orders_status_check");
        } catch (\Exception $e) {
            // Ignore
        }

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN (
            'pending', 'pending_confirmation', 'confirmed', 'in_progress',
            'ready', 'served', 'paid', 'cancelled', 'pending_payment',
            'PENDING_PAYMENT', 'PREPARATION', 'ASSEMBLING',
            'READY_FOR_DELIVERY', 'OUT_FOR_DELIVERY', 'CANCELLATION_REQUESTED',
            'DELIVERED', 'FAILED_DELIVERY', 'CANCELLED',
            'READY', 'SERVED'
        ))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE orders MODIFY COLUMN `status` ENUM(
            'pending', 'pending_confirmation', 'confirmed', 'in_progress',
            'ready', 'served', 'paid', 'cancelled', 'pending_payment',
            'PENDING_PAYMENT', 'PREPARATION', 'ASSEMBLING',
            'READY_FOR_DELIVERY', 'OUT_FOR_DELIVERY', 'CANCELLATION_REQUESTED',
            'DELIVERED', 'FAILED_DELIVERY', 'CANCELLED'
        ) DEFAULT 'PENDING_PAYMENT'");
    }
};
