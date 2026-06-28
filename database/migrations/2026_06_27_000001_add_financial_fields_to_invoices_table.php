<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('entity_type')->nullable()->after('customer_id');
            $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');
            $table->decimal('paid_amount', 12, 2)->default(0)->after('total');
            $table->decimal('remaining_amount', 12, 2)->default(0)->after('paid_amount');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('description')->nullable()->after('item_name');
            $table->decimal('unit_price', 12, 2)->nullable()->after('description');
            $table->unsignedBigInteger('account_id')->nullable()->after('unit_price');
            $table->unsignedBigInteger('branch_id')->nullable()->after('account_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['entity_type', 'entity_id', 'paid_amount', 'remaining_amount']);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['description', 'unit_price', 'account_id', 'branch_id']);
        });
    }
};
