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
        if (! Schema::hasTable('invoices')) return;
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'type')) {
                $table->string('type')->nullable()->after('number');
            }
            if (! Schema::hasColumn('invoices', 'customer_name')) {
                $table->string('customer_name')->nullable()->after('customer_id');
            }
            if (! Schema::hasColumn('invoices', 'entity_type')) {
                $table->string('entity_type')->nullable()->after('customer_name');
            }
            if (! Schema::hasColumn('invoices', 'entity_id')) {
                $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');
            }
            if (! Schema::hasColumn('invoices', 'currency')) {
                $table->string('currency', 10)->nullable()->default('ILS')->after('payment_method');
            }
            if (! Schema::hasColumn('invoices', 'tax_total')) {
                $table->decimal('tax_total', 15, 2)->default(0)->after('discount');
            }
            if (! Schema::hasColumn('invoices', 'paid_amount')) {
                $table->decimal('paid_amount', 15, 2)->default(0)->after('total');
            }
            if (! Schema::hasColumn('invoices', 'remaining_amount')) {
                $table->decimal('remaining_amount', 15, 2)->default(0)->after('paid_amount');
            }
            if (! Schema::hasColumn('invoices', 'payment_method_id')) {
                $table->unsignedBigInteger('payment_method_id')->nullable()->after('payment_method');
            }
            if (! Schema::hasColumn('invoices', 'due_date')) {
                $table->date('due_date')->nullable()->after('invoice_date');
            }
            if (! Schema::hasColumn('invoices', 'delivery_date')) {
                $table->date('delivery_date')->nullable()->after('due_date');
            }
            if (! Schema::hasColumn('invoices', 'expected_payment_date')) {
                $table->date('expected_payment_date')->nullable()->after('delivery_date');
            }
            if (! Schema::hasColumn('invoices', 'reference_number')) {
                $table->string('reference_number')->nullable()->after('expected_payment_date');
            }
            if (! Schema::hasColumn('invoices', 'supply_date')) {
                $table->date('supply_date')->nullable()->after('expected_payment_date');
            }
            if (! Schema::hasColumn('invoices', 'account_number')) {
                $table->string('account_number')->nullable()->after('reference_number');
            }
            if (! Schema::hasColumn('invoices', 'pos_register_id')) {
                $table->unsignedBigInteger('pos_register_id')->nullable()->after('branch_id');
            }
            if (! Schema::hasColumn('invoices', 'pos_code')) {
                $table->string('pos_code')->nullable()->after('pos_register_id');
            }
            if (! Schema::hasColumn('invoices', 'pos_name')) {
                $table->string('pos_name')->nullable()->after('pos_code');
            }
            if (! Schema::hasColumn('invoices', 'opened_by')) {
                $table->unsignedBigInteger('opened_by')->nullable()->after('pos_name');
            }
            if (! Schema::hasColumn('invoices', 'opened_at')) {
                $table->timestamp('opened_at')->nullable()->after('opened_by');
            }
            if (! Schema::hasColumn('invoices', 'closed_by')) {
                $table->unsignedBigInteger('closed_by')->nullable()->after('opened_at');
            }
            if (! Schema::hasColumn('invoices', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('closed_by');
            }
            if (! Schema::hasColumn('invoices', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('closed_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoices')) return;
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'type', 'customer_name', 'entity_type', 'entity_id',
                'currency', 'tax_total', 'paid_amount', 'remaining_amount',
                'payment_method_id', 'due_date', 'delivery_date',
                'expected_payment_date', 'reference_number', 'supply_date',
                'account_number', 'pos_register_id', 'pos_code', 'pos_name',
                'opened_by', 'opened_at', 'closed_by', 'closed_at', 'approved_at',
            ]);
        });
    }
};
