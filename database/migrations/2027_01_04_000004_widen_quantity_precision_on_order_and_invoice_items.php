<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أصناف الوزن (بالكيلو): الكاشير بيحط إجمالي السطر (مثلاً 10 ₪) والنظام
 * بيشتقّ الكمية = الإجمالي ÷ السعر (مثلاً 10 ÷ 35 = 0.2857). عمود decimal(10,2)
 * كان يقصّها لخانتين (0.29) فيرجع السطر 10.15 بدل 10 — «ما بحط الرقم الصح» —
 * وكمان يخلي إجمالي الباك اند يختلف عن الظاهر للكاشير وقت الدفع.
 * التوسيع لـ decimal(15,4) بيخلي الكمية المشتقّة دقيقة كفاية ترجع نفس الإجمالي
 * المطلوب، ويطابق sales_invoice_items اللي أصلاً decimal(15,4).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('order_items', 'quantity')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->decimal('quantity', 15, 4)->default(1)->change();
            });
        }

        if (Schema::hasColumn('invoice_items', 'quantity')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->decimal('quantity', 15, 4)->default(1)->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_items', 'quantity')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->decimal('quantity', 10, 2)->default(1)->change();
            });
        }

        if (Schema::hasColumn('invoice_items', 'quantity')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->decimal('quantity', 10, 2)->default(1)->change();
            });
        }
    }
};
