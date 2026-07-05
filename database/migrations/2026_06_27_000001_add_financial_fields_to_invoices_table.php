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
                if (! Schema::hasColumn('invoices', 'entity_type')) {
                    $table->string('entity_type')->nullable()->after('customer_id');
                    $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');
                }
                if (! Schema::hasColumn('invoices', 'paid_amount')) {
                    $table->decimal('paid_amount', 12, 2)->default(0)->after('total');
                    $table->decimal('remaining_amount', 12, 2)->default(0)->after('paid_amount');
                }
            });
        }

        if (Schema::hasTable('invoice_items')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                if (! Schema::hasColumn('invoice_items', 'description')) {
                    $table->string('description')->nullable()->after('item_name');
                }
                if (! Schema::hasColumn('invoice_items', 'unit_price')) {
                    $table->decimal('unit_price', 12, 2)->nullable()->after('description');
                }
                if (! Schema::hasColumn('invoice_items', 'account_id')) {
                    $table->unsignedBigInteger('account_id')->nullable()->after('unit_price');
                }
                if (! Schema::hasColumn('invoice_items', 'branch_id')) {
                    $table->unsignedBigInteger('branch_id')->nullable()->after('account_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn(['entity_type', 'entity_id', 'paid_amount', 'remaining_amount']);
            });
        }

        if (Schema::hasTable('invoice_items')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->dropColumn(['description', 'unit_price', 'account_id', 'branch_id']);
            });
        }
    }
};
