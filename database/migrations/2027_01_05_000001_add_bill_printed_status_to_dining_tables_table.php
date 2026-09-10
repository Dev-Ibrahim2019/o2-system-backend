<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * إضافة حالة BILL_PRINTED (فاتورة الزبون مطبوعة — الطاولة تضوي أزرق)
     * لقائمة الحالات المسموحة على dining_tables.
     */
    public function up(): void
    {
        try {
            DB::statement("ALTER TABLE dining_tables DROP CONSTRAINT dining_tables_status_check");
        } catch (\Throwable $e) {
            // Constraint may not exist
        }

        DB::statement("ALTER TABLE dining_tables ADD CONSTRAINT dining_tables_status_check CHECK (status IN ('AVAILABLE', 'OCCUPIED', 'PAYMENT_PENDING', 'BILL_PRINTED', 'PAID', 'RESERVED', 'CLEANING', 'HAS_ORDER', 'PENDING_CONFIRMATION', 'MERGED'))");
    }

    public function down(): void
    {
        try {
            DB::statement("ALTER TABLE dining_tables DROP CONSTRAINT dining_tables_status_check");
        } catch (\Throwable $e) {
            // Constraint may not exist
        }

        DB::statement("ALTER TABLE dining_tables ADD CONSTRAINT dining_tables_status_check CHECK (status IN ('AVAILABLE', 'OCCUPIED', 'PAYMENT_PENDING', 'PAID', 'RESERVED', 'CLEANING', 'HAS_ORDER', 'PENDING_CONFIRMATION', 'MERGED'))");
    }
};
