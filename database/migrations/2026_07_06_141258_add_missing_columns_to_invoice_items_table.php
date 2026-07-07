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
        Schema::table('invoice_items', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_items', 'description')) {
                $table->string('description')->nullable()->after('item_name');
            }
            if (! Schema::hasColumn('invoice_items', 'unit_price')) {
                $table->decimal('unit_price', 15, 3)->nullable()->after('price');
            }
            if (! Schema::hasColumn('invoice_items', 'discount')) {
                $table->decimal('discount', 15, 3)->default(0)->after('unit_price');
            }
            if (! Schema::hasColumn('invoice_items', 'total_before_tax')) {
                $table->decimal('total_before_tax', 15, 3)->default(0)->after('discount');
            }
            if (! Schema::hasColumn('invoice_items', 'account_id')) {
                $table->unsignedBigInteger('account_id')->nullable()->after('total_before_tax');
            }
            if (! Schema::hasColumn('invoice_items', 'branch_id')) {
                $table->unsignedBigInteger('branch_id')->nullable()->after('account_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn([
                'description', 'unit_price', 'discount',
                'total_before_tax', 'account_id', 'branch_id',
            ]);
        });
    }
};
