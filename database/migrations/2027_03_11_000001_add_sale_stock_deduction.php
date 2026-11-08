<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * خصم المخزون عند البيع حسب الوصفات.
 *
 * - inventory_settings.deduct_sales: تشغيل/إيقاف (افتراضياً موقوف، فلا يتغير شيء للبيانات الحالية).
 *   deduct_sales_from: لحظة آخر تشغيل — لا يُخصم إلا ما دُفع بعدها (لا ترحيل بأثر رجعي للطلبات القديمة).
 * - stock_sale_postings: سجل لكل طلب مدفوع: خُصم، جزئي (أسطر بلا وصفة/مخزن)، لا شيء للخصم، فشل، معكوس.
 *   يمنع الخصم المكرر ويُظهر ما فشل لإعادة المحاولة. الحركات نفسها في stock_movements (source_type = order).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_settings', function (Blueprint $t) {
            $t->boolean('deduct_sales')->default(false)->after('allow_negative_stock');
            $t->timestamp('deduct_sales_from')->nullable()->after('deduct_sales');
        });

        Schema::create('stock_sale_postings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            // posted | partial | skipped | failed | reversed
            $t->string('status', 20)->index();
            $t->unsignedInteger('movements_count')->default(0);
            $t->decimal('total_cost', 18, 6)->default(0);
            // أسطر لم تُخصم وسببها: [{order_item_id, item_id, name, reason: no_recipe|no_warehouse}]
            $t->json('issues')->nullable();
            $t->text('error')->nullable();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('posted_at')->nullable();
            $t->timestamp('reversed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_sale_postings');
        Schema::table('inventory_settings', function (Blueprint $t) {
            $t->dropColumn(['deduct_sales', 'deduct_sales_from']);
        });
    }
};
