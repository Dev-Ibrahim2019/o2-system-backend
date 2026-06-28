<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('type')->default('فاتورة ضريبية')->after('number');
            $table->string('currency', 10)->default('SAR')->after('status');
            $table->decimal('tax_total', 12, 2)->default(0)->after('paid_amount');
            $table->date('due_date')->nullable()->after('invoice_date');
            $table->date('delivery_date')->nullable()->after('due_date');
            $table->date('expected_payment_date')->nullable()->after('delivery_date');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('discount', 12, 2)->default(0)->after('unit_price');
            $table->decimal('total_before_tax', 12, 2)->default(0)->after('discount');
            $table->decimal('tax_rate', 5, 2)->default(15)->after('total_before_tax');
            $table->decimal('tax_amount', 12, 2)->default(0)->after('tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['type', 'currency', 'tax_total', 'due_date', 'delivery_date', 'expected_payment_date']);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['discount', 'total_before_tax', 'tax_rate', 'tax_amount']);
        });
    }
};
