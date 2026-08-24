<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The only new column needed to complete CRM Customer Identity — every
 * other requested field (title/nickname, source, birth_date, work address)
 * already exists via customers.title, customers.source, customer_occasions,
 * and customer_addresses respectively. Nullable, plain varchar (no DB enum),
 * no default — existing customers are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('gender', 10)->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};
