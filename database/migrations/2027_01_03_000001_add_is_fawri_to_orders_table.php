<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * يميّز طلب «فوري» (بيع كاشير سريع) عن طلب «takeaway» العادي.
 * order_type يبقى كما هو (dine_in / takeaway / delivery)؛ هذا العمود
 * يخزّن فقط إن كان الإغلاق تم من مسار «فوري» في نقطة البيع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_fawri')->default(false)->after('order_type');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('is_fawri');
        });
    }
};
