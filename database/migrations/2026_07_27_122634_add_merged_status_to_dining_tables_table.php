<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // إضافة حالة MERGED لقائمة الحالات المسموحة
        try {
            DB::statement("ALTER TABLE dining_tables DROP CHECK dining_tables_status_check");
        } catch (\Throwable $e) {
            // Constraint may not exist
        }

        DB::statement("ALTER TABLE dining_tables ADD CONSTRAINT dining_tables_status_check CHECK (status IN ('AVAILABLE', 'OCCUPIED', 'PAYMENT_PENDING', 'PAID', 'RESERVED', 'CLEANING', 'HAS_ORDER', 'PENDING_CONFIRMATION', 'MERGED'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // إرجاع القيد بدون MERGED
        try {
            DB::statement("ALTER TABLE dining_tables DROP CHECK dining_tables_status_check");
        } catch (\Throwable $e) {
            // Constraint may not exist
        }

        DB::statement("ALTER TABLE dining_tables ADD CONSTRAINT dining_tables_status_check CHECK (status IN ('AVAILABLE', 'OCCUPIED', 'PAYMENT_PENDING', 'PAID', 'RESERVED', 'CLEANING', 'HAS_ORDER', 'PENDING_CONFIRMATION'))");
    }
};
