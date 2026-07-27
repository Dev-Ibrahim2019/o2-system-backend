<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Ensures ALL columns from earlier migrations (pre-September 2026) that may
     * have been skipped because the orders table didn't exist yet.
     * This is a comprehensive safety net that guarantees every expected column exists.
     */
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        $cols = Schema::getColumnListing('orders');

        Schema::table('orders', function (Blueprint $table) use ($cols): void {
            // === From 2026_07_10_000004_add_call_center_fields_to_orders_table ===
            if (!in_array('customer_mobile', $cols, true)) {
                $table->string('customer_mobile', 30)->nullable();
            }
            if (!in_array('needs_attention', $cols, true)) {
                $table->boolean('needs_attention')->default(false);
            }
            if (!in_array('customer_service_flag', $cols, true)) {
                $table->boolean('customer_service_flag')->default(false);
            }
            if (!in_array('customer_notes', $cols, true)) {
                $table->text('customer_notes')->nullable();
            }
            if (!in_array('delivery_notes', $cols, true)) {
                $table->text('delivery_notes')->nullable();
            }
            if (!in_array('call_notes', $cols, true)) {
                $table->text('call_notes')->nullable();
            }
            if (!in_array('call_center_agent_id', $cols, true)) {
                $table->foreignId('call_center_agent_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (!in_array('source', $cols, true)) {
                $table->string('source', 50)->default('call_center');
            }

            // === From 2026_07_19_180459_add_missing_columns_to_orders_table ===
            if (!in_array('customer_id', $cols, true)) {
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            }
            if (!in_array('employee_id', $cols, true)) {
                $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            }
            if (!in_array('supplier_id', $cols, true)) {
                $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            }
            if (!in_array('engine_discount_amount', $cols, true)) {
                $table->decimal('engine_discount_amount', 10, 3)->default(0);
            }

            // === From 2026_07_10_235000_add_delivery_workflow_timestamps_to_orders ===
            if (!in_array('driver_id', $cols, true)) {
                $table->foreignId('driver_id')->nullable()->constrained('employees')->nullOnDelete();
            }
            if (!in_array('cancellation_reason', $cols, true)) {
                $table->text('cancellation_reason')->nullable();
            }
            if (!in_array('cancelled_at', $cols, true)) {
                $table->datetime('cancelled_at')->nullable();
            }

            // === From 2026_07_20_103603_add_delivery_zone_id_to_orders_table ===
            // NOTE: Uses unsignedBigInteger (not foreignId) because the referenced table may not exist yet
            // Foreign key will be added by a later migration
            if (!in_array('delivery_zone_id', $cols, true)) {
                $table->unsignedBigInteger('delivery_zone_id')->nullable()->index();
            }

            // === From 2026_07_25_000001_add_missing_call_center_columns ===
            // (already covered above: customer_mobile, needs_attention, etc.)

            // === Our new migration: customer_address_id, delivery_fee, delivery_address_snapshot, paid_amount, paid_at ===
            // NOTE: Uses unsignedBigInteger (not foreignId) because the referenced table may not exist yet
            if (!in_array('customer_address_id', $cols, true)) {
                $table->unsignedBigInteger('customer_address_id')->nullable()->index();
            }
            if (!in_array('delivery_fee', $cols, true)) {
                $table->decimal('delivery_fee', 10, 3)->default(0);
            }
            if (!in_array('delivery_address_snapshot', $cols, true)) {
                $table->json('delivery_address_snapshot')->nullable();
            }
            if (!in_array('payment_status', $cols, true)) {
                $table->string('payment_status', 20)->default('UNPAID');
            }
            if (!in_array('paid_amount', $cols, true)) {
                $table->decimal('paid_amount', 10, 3)->default(0);
            }
            if (!in_array('paid_at', $cols, true)) {
                $table->timestamp('paid_at')->nullable();
            }
            if (!in_array('transaction_id', $cols, true)) {
                $table->string('transaction_id')->nullable();
            }

            // === From 2026_07_15_160000_add_quality_and_urgency_to_orders ===
            if (!in_array('is_urgent', $cols, true)) {
                $table->boolean('is_urgent')->default(false);
            }
            if (!in_array('priority', $cols, true)) {
                $table->string('priority', 20)->default('normal');
            }
            if (!in_array('expedited_at', $cols, true)) {
                $table->timestamp('expedited_at')->nullable();
            }
            if (!in_array('expedited_by', $cols, true)) {
                $table->unsignedBigInteger('expedited_by')->nullable();
            }

            // === Fix the ENUM to accept new status values ===
            // The original migration uses ENUM with old values; we need to expand it.
            // MySQL ENUM expansion requires the column to be VARCHAR first, then back to ENUM.
            try {
                DB::statement("ALTER TABLE orders MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'PENDING_PAYMENT'");
            } catch (\Throwable $e) {
                // May fail if already modified
            }

            // === From 2026_09_29_000001 (status check constraint) ===
            // Fix the CHECK constraint to use uppercase values matching the code
            try {
                DB::statement('ALTER TABLE orders DROP CHECK orders_status_check');
            } catch (\Throwable $e) {
                // May not exist
            }
        });
    }

    public function down(): void
    {
        // No reverse — this is a safety net; reversing would break the app
    }
};