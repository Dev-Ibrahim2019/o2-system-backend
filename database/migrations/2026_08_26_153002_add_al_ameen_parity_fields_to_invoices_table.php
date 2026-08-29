<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('exchange_rate', 12, 6)->default(1)->after('currency');
            $table->unsignedInteger('daily_sequence')->nullable()->after('number');
            $table->string('financial_voucher_number', 100)->nullable()->after('reference_number');
            $table->string('vat_report_number', 100)->nullable()->after('financial_voucher_number');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['exchange_rate', 'daily_sequence', 'financial_voucher_number', 'vat_report_number']);
        });
    }
};
