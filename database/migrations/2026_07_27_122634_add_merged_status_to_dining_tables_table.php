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
        // SQLite cannot alter CHECK constraints, and the create migration
        // never added one there in the first place (same guard) — so on the
        // test database there is nothing to amend. Without this guard the
        // whole test suite failed to migrate before a single test ran.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

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
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

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
