<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the two free-text columns on customer_occasions and gives the table
 * the indexes a reminder query will need.
 *
 * Both columns were varchar(50) with their intended values recorded only in a
 * comment on the original migration — the same shape the `category` column had
 * before it was split, and the reason nothing stopped a typo from becoming a
 * new "type". The application-level validator did not constrain them either
 * (`required|string|max:50`, no `in:`), so the database is the first place
 * that can actually refuse a bad value.
 *
 * Safe to constrain now: verified before writing this migration that the table
 * holds 1 row, occasion_type is 'birthday' only, preferred_contact_method is
 * NULL throughout, and zero rows violate either new enum.
 *
 * 'graduation' and 'contract_renewal' are new to the list; 'special' and
 * 'reminder' from the original comment are deliberately dropped — neither was
 * ever written, and 'other' covers them.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Raw statements rather than Blueprint->change(): doctrine/dbal does
        // not model MySQL enums, and change() on an enum column silently
        // rewrites it as varchar.
        DB::statement("
            ALTER TABLE customer_occasions
            MODIFY occasion_type ENUM(
                'birthday','anniversary','graduation','company_founding','contract_renewal','other'
            ) NOT NULL
        ");

        DB::statement("
            ALTER TABLE customer_occasions
            MODIFY preferred_contact_method ENUM('call','sms','email','whatsapp') NULL
        ");

        Schema::table('customer_occasions', function (Blueprint $table) {
            // A reminder sweep filters on is_active and scans by date; the
            // table had indexes on customer_id and created_by only, so every
            // such query was a full scan.
            $table->index(['is_active', 'date'], 'co_active_date_index');
            $table->index('occasion_type', 'co_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('customer_occasions', function (Blueprint $table) {
            $table->dropIndex('co_active_date_index');
            $table->dropIndex('co_type_index');
        });

        DB::statement('ALTER TABLE customer_occasions MODIFY occasion_type VARCHAR(50) NOT NULL');
        DB::statement('ALTER TABLE customer_occasions MODIFY preferred_contact_method VARCHAR(50) NULL');
    }
};
