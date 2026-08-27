<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        try {
            DB::statement("
                ALTER TABLE dining_tables
                DROP CHECK dining_tables_status_check
            ");
        } catch (\Throwable $e) {
            // القيد غير موجود، نكمل
        }

        DB::statement("
            ALTER TABLE dining_tables
            ADD CONSTRAINT dining_tables_status_check
            CHECK (
                status IN (
                    'AVAILABLE',
                    'OCCUPIED',
                    'PAYMENT_PENDING',
                    'PAID',
                    'RESERVED',
                    'CLEANING',
                    'HAS_ORDER',
                    'PENDING_CONFIRMATION',
                    'MERGED'
                )
            )
        ");
    }

    public function down(): void
    {
        try {
            DB::statement("
                ALTER TABLE dining_tables
                DROP CHECK dining_tables_status_check
            ");
        } catch (\Throwable $e) {
            // القيد غير موجود، نكمل
        }

        DB::statement("
            ALTER TABLE dining_tables
            ADD CONSTRAINT dining_tables_status_check
            CHECK (
                status IN (
                    'AVAILABLE',
                    'OCCUPIED',
                    'PAYMENT_PENDING',
                    'PAID',
                    'RESERVED',
                    'CLEANING',
                    'HAS_ORDER',
                    'PENDING_CONFIRMATION'
                )
            )
        ");
    }
};