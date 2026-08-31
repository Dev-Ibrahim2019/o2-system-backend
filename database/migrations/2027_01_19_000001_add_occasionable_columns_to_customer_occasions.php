<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 1 of 3 — add the polymorphic columns alongside the existing FK.
 *
 * Nullable here on purpose: this migration only widens the table, so it can
 * run against live data without a single row failing. The backfill (step 2)
 * fills them and the tightening (step 3) makes them required and drops
 * customer_id, once the data has been verified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_occasions', function (Blueprint $table) {
            $table->string('occasionable_type')->nullable()->after('customer_id');
            $table->unsignedBigInteger('occasionable_id')->nullable()->after('occasionable_type');

            $table->index(['occasionable_type', 'occasionable_id'], 'co_occasionable_index');
        });
    }

    public function down(): void
    {
        Schema::table('customer_occasions', function (Blueprint $table) {
            $table->dropIndex('co_occasionable_index');
            $table->dropColumn(['occasionable_type', 'occasionable_id']);
        });
    }
};
