<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // تحويل أي طاولات بحالة HAS_ORDER إلى OCCUPIED
        DB::table('dining_tables')->where('status', 'HAS_ORDER')->update(['status' => 'OCCUPIED']);

        // The data fix above runs everywhere; the constraint amendment below
        // cannot — SQLite has no ALTER for CHECK constraints and the create
        // migration never added one there (same guard), so on the test
        // database there is nothing to amend.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // تحديث CHECK constraint لإزالة HAS_ORDER
        DB::statement("ALTER TABLE dining_tables DROP CONSTRAINT dining_tables_status_check");
        DB::statement("ALTER TABLE dining_tables ADD CONSTRAINT dining_tables_status_check CHECK (status IN ('AVAILABLE', 'OCCUPIED', 'PAYMENT_PENDING', 'PAID', 'RESERVED', 'CLEANING', 'PENDING_CONFIRMATION', 'MERGED'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE dining_tables DROP CONSTRAINT dining_tables_status_check");
        DB::statement("ALTER TABLE dining_tables ADD CONSTRAINT dining_tables_status_check CHECK (status IN ('AVAILABLE', 'OCCUPIED', 'PAYMENT_PENDING', 'PAID', 'RESERVED', 'CLEANING', 'HAS_ORDER', 'PENDING_CONFIRMATION', 'MERGED'))");
    }
};
