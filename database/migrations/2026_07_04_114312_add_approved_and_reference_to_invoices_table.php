<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('invoices')) {
            return;
        }

        if (! Schema::hasColumn('invoices', 'approved_by')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('account_number');
            });
        }

        if (! Schema::hasColumn('invoices', 'approved_at')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dateTime('approved_at')->nullable()->after('approved_by');
            });
        }

        if (! Schema::hasColumn('invoices', 'supply_date')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->date('supply_date')->nullable()->after('due_date');
            });
        }

        if (! Schema::hasColumn('invoices', 'reference_number')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->string('reference_number', 100)->nullable()->after('account_number');
            });
        }
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['approved_by', 'approved_at', 'supply_date', 'reference_number']);
        });
    }
};