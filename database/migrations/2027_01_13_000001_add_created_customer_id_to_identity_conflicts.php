<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which customer a split resolution created.
 *
 * created_new_customer / marked_shared_number already record this in
 * resolution_note as Arabic prose ("أُنشئ عميل جديد #29 …") — fine for a human
 * reading the ticket, useless to code. Reassigning orders to that customer
 * needs the id programmatically, and parsing it back out of a sentence would
 * be a bug waiting to happen.
 *
 * Null for the three resolutions that create nobody.
 * nullOnDelete: the ticket stays as a record even if the customer is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_identity_conflicts', function (Blueprint $table) {
            $table->foreignId('created_customer_id')
                ->nullable()
                ->after('resolution')
                ->constrained('customers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_identity_conflicts', function (Blueprint $table) {
            $table->dropForeign(['created_customer_id']);
            $table->dropColumn('created_customer_id');
        });
    }
};
