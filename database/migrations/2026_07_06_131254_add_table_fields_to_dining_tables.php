<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dining_tables', function (Blueprint $table) {
            // الطلب النشط الحالي على الطاولة (current_order_id)
            $table->foreignId('current_order_id')->nullable()->constrained('orders')->nullOnDelete();

            // وقت الجلوس
            $table->timestamp('seated_at')->nullable()->after('current_order_id');

            // عدد الزبائن
            $table->integer('customer_count')->default(0)->after('seated_at');

            // آخر طلب انتهى على الطاولة
            $table->timestamp('last_order_at')->nullable()->after('customer_count');
        });
    }

    public function down(): void
    {
        Schema::table('dining_tables', function (Blueprint $table) {
            $table->dropForeign(['current_order_id']);
            $table->dropColumn([
                'current_order_id',
                'seated_at',
                'customer_count',
                'last_order_at',
            ]);
        });
    }
};
