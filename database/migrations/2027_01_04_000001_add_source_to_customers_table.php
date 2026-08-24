<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an optional acquisition-channel field for the CRM dashboard's
 * "customer sources" breakdown (واتساب / إحالة / اتصال / الموقع الإلكتروني / أخرى).
 * Nullable, no default — existing customers simply have no source recorded
 * and are grouped as "غير محدد" by the dashboard aggregate, not backfilled
 * with a guessed value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('source', 30)->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
