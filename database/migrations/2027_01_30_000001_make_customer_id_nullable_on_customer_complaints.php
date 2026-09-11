<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A complaint that is not about one customer — a recurring problem, a
 * process failure, a general note about a branch or a department — had no
 * home: customer_id was NOT NULL, so "إنشاء شكوى" always forced picking a
 * customer first. This is the one schema change that makes a "شكوى عامة"
 * (Crm\ComplaintController's guide already describes the concept) actually
 * creatable.
 *
 * Raw ALTER rather than ->nullable()->change(), matching
 * 2026_07_10_132100_add_nullable_to_subject_in_customer_complaints_table —
 * this project has no doctrine/dbal dependency for Blueprint::change().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('customer_complaints', function (Blueprint $table) {
                $table->unsignedBigInteger('customer_id')->nullable()->change();
            });
            return;
        }

        DB::statement('ALTER TABLE customer_complaints MODIFY customer_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        // Any general complaint (customer_id IS NULL) would violate the
        // restored NOT NULL constraint — remove them first so the rollback
        // itself does not fail.
        DB::table('customer_complaints')->whereNull('customer_id')->delete();

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('customer_complaints', function (Blueprint $table) {
                $table->unsignedBigInteger('customer_id')->nullable(false)->change();
            });
            return;
        }

        DB::statement('ALTER TABLE customer_complaints MODIFY customer_id BIGINT UNSIGNED NOT NULL');
    }
};
