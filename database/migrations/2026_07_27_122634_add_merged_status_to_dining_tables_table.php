<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE dining_tables DROP CHECK dining_tables_status_check");

        DB::statement("
        ALTER TABLE dining_tables
        ADD CONSTRAINT dining_tables_status_check
        CHECK (status IN (
            'AVAILABLE',
            'OCCUPIED',
            'PAYMENT_PENDING',
            'PAID',
            'RESERVED',
            'CLEANING',
            'HAS_ORDER',
            'PENDING_CONFIRMATION',
            'MERGED'
        ))
    ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE dining_tables DROP CHECK dining_tables_status_check");

        DB::statement("
        ALTER TABLE dining_tables
        ADD CONSTRAINT dining_tables_status_check
        CHECK (status IN (
            'AVAILABLE',
            'OCCUPIED',
            'PAYMENT_PENDING',
            'PAID',
            'RESERVED',
            'CLEANING',
            'HAS_ORDER',
            'PENDING_CONFIRMATION'
        ))
    ");
    }
};
