<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                if (! Schema::hasColumn('invoices', 'type')) {
                    $table->string('type')->default('فاتورة ضريبية')->after('number');
                }
                if (! Schema::hasColumn('invoices', 'currency')) {
                    $table->string('currency', 10)->default('ILS')->after('status');
                }
                if (! Schema::hasColumn('invoices', 'tax_total')) {
                    $table->decimal('tax_total', 12, 2)->default(0)->after('paid_amount');
                }
                if (! Schema::hasColumn('invoices', 'due_date')) {
                    $table->date('due_date')->nullable()->after('invoice_date');
                    $table->date('delivery_date')->nullable()->after('due_date');
                    $table->date('expected_payment_date')->nullable()->after('delivery_date');
                }
            });
        }

        if (Schema::hasTable('invoice_items')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                if (! Schema::hasColumn('invoice_items', 'discount')) {
                    $table->decimal('discount', 12, 2)->default(0)->after('unit_price');
                }
                if (! Schema::hasColumn('invoice_items', 'total_before_tax')) {
                    $table->decimal('total_before_tax', 12, 2)->default(0)->after('discount');
                }
                if (! Schema::hasColumn('invoice_items', 'tax_rate')) {
                    $table->decimal('tax_rate', 5, 2)->default(15)->after('total_before_tax');
                }
                if (! Schema::hasColumn('invoice_items', 'tax_amount')) {
                    $table->decimal('tax_amount', 12, 2)->default(0)->after('tax_rate');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn(['type', 'currency', 'tax_total', 'due_date', 'delivery_date', 'expected_payment_date']);
            });
        }

        if (Schema::hasTable('invoice_items')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->dropColumn(['discount', 'total_before_tax', 'tax_rate', 'tax_amount']);
            });
        }
    }
};
