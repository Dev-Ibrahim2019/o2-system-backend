<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * item_id / department_id لم يكن عليهما index — رغم أنهما الأعمدة الأكثر
     * استخداماً بالـ WHERE/JOIN/groupBy لتوجيه المطبخ والتقارير. لا نضيف
     * foreign key constraint هون تجنباً لفشل الترحيل على بيانات تجريبية
     * قديمة قد تحتوي قيم يتيمة — فقط index لتسريع الاستعلامات.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->index('item_id');
            $table->index('department_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['item_id']);
            $table->dropIndex(['department_id']);
        });
    }
};
