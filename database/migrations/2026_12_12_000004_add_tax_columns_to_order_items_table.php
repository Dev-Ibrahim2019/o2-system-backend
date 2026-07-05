<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('order_items', 'tax_rate')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->decimal('tax_rate', 5, 2)->nullable()->after('discount_apply_strategy');
            });
        }
        if (!Schema::hasColumn('order_items', 'tax_amount')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->decimal('tax_amount', 15, 3)->nullable()->after('tax_rate');
            });
        }
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['tax_rate', 'tax_amount']);
        });
    }
};
