<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoice_items')) return;

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('original_price', 15, 3)->nullable()->after('price');
            $table->decimal('discount_amount', 15, 3)->default(0)->after('total');
            $table->decimal('discount_percent', 5, 2)->nullable()->after('discount_amount');
            if (Schema::hasTable('discounts')) {
                $table->foreignId('discount_id')->nullable()->constrained('discounts')->nullOnDelete()->after('discount_percent');
            }
            $table->decimal('final_price', 15, 3)->default(0)->after('discount_percent');
            $table->decimal('subtotal', 15, 3)->nullable()->after('price');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoice_items')) return;

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn([
                'original_price',
                'discount_amount',
                'discount_percent',
                'discount_id',
                'final_price',
                'subtotal',
            ]);
        });
    }
};
