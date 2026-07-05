<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Fix: الأعمدة المفقودة original_price, subtotal, discount_amount, discount_percent, discount_id, final_price
     * كانت مفقودة لأن الـ migration السابق (2026_06_23) تشغّل قبل إنشاء جدول invoice_items (2026_12_11)
     */
    public function up(): void
    {
        if (! Schema::hasTable('invoice_items')) {
            return;
        }

        Schema::table('invoice_items', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_items', 'original_price')) {
                $table->decimal('original_price', 15, 3)->nullable()->after('price');
            }
            if (! Schema::hasColumn('invoice_items', 'subtotal')) {
                $table->decimal('subtotal', 15, 3)->nullable()->after('price');
            }
            if (! Schema::hasColumn('invoice_items', 'discount_amount')) {
                $table->decimal('discount_amount', 15, 3)->default(0)->after('total');
            }
            if (! Schema::hasColumn('invoice_items', 'discount_percent')) {
                $table->decimal('discount_percent', 5, 2)->nullable()->after('discount_amount');
            }
            if (! Schema::hasColumn('invoice_items', 'discount_id')) {
                $table->foreignId('discount_id')->nullable()->constrained('discounts')->nullOnDelete();
            }
            if (! Schema::hasColumn('invoice_items', 'discount_apply_strategy')) {
                $table->string('discount_apply_strategy', 30)->nullable()->after('discount_id');
            }
            if (! Schema::hasColumn('invoice_items', 'final_price')) {
                $table->decimal('final_price', 15, 3)->default(0)->after('discount_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            if (Schema::hasColumn('invoice_items', 'discount_id')) {
                $table->dropConstrainedForeignId('discount_id');
            }
            foreach (['final_price', 'discount_percent', 'discount_amount', 'discount_apply_strategy', 'subtotal', 'original_price'] as $column) {
                if (Schema::hasColumn('invoice_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
