<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            // الحقول الجديدة لدعم الخصومات لكل بند فاتورة
            $table->decimal('original_price', 15, 3)->nullable()->after('price')->comment('السعر الأصلي قبل الخصم');
            $table->decimal('discount_amount', 15, 3)->default(0)->after('total')->comment('قيمة الخصم');
            $table->decimal('discount_percent', 5, 2)->nullable()->after('discount_amount')->comment('نسبة الخصم');
            $table->foreignId('discount_id')->nullable()->constrained('discounts')->nullOnDelete()->after('discount_percent');
            $table->decimal('final_price', 15, 3)->default(0)->after('discount_id')->comment('السعر النهائي بعد الخصم');

            // إعادة تسمية حقل total ليكون subtotal للتوضيح
            // total سيظل كما هو للتوافق مع النظام الحالي
            $table->decimal('subtotal', 15, 3)->nullable()->after('price')->comment('المجموع الفرعي (price * quantity) قبل الخصم');
        });
    }

    public function down(): void
    {
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
