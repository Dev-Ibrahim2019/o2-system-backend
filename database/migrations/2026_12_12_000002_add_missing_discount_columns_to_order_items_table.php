<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Fix: الأعمدة المفقودة original_price, final_price, discount_amount, discount_percent, discount_id
     * كانت مفقودة لأن الـ migration السابق (2026_06_25) تشغّل قبل إنشاء جدول order_items (2026_12_11)
     */
    public function up(): void
    {
        if (! Schema::hasTable('order_items')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'original_price')) {
                $table->decimal('original_price', 15, 2)->nullable()->after('price');
            }
            if (! Schema::hasColumn('order_items', 'final_price')) {
                $table->decimal('final_price', 15, 2)->nullable()->after('original_price');
            }
            if (! Schema::hasColumn('order_items', 'discount_amount')) {
                $table->decimal('discount_amount', 15, 2)->default(0)->after('final_price');
            }
            if (! Schema::hasColumn('order_items', 'discount_percent')) {
                $table->decimal('discount_percent', 8, 2)->nullable()->after('discount_amount');
            }
            if (! Schema::hasColumn('order_items', 'discount_id')) {
                $table->foreignId('discount_id')->nullable()->after('discount_percent')
                    ->constrained('discounts')->nullOnDelete();
            }
            if (! Schema::hasColumn('order_items', 'discount_apply_strategy')) {
                $table->string('discount_apply_strategy', 30)->nullable()->after('discount_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'discount_id')) {
                $table->dropConstrainedForeignId('discount_id');
            }
            foreach (['discount_percent', 'discount_amount', 'final_price', 'original_price', 'discount_apply_strategy'] as $column) {
                if (Schema::hasColumn('order_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
