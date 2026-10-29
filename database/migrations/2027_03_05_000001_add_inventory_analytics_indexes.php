<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فهارس تحليلات المخزون (إضافة فقط، لا تغيّر بيانات):
 * - orders(payment_status, paid_at): مبيعات الفترة المدفوعة — أساس الاستهلاك النظري.
 * - stock_movements(stock_item_id, accounting_day): استهلاك صنف عبر الزمن وتاريخ آخر حركة (الراكد).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->index(['payment_status', 'paid_at'], 'orders_paid_period_index'));
        Schema::table('stock_movements', fn (Blueprint $t) => $t->index(['stock_item_id', 'accounting_day'], 'stock_movements_item_day_index'));
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropIndex('orders_paid_period_index'));
        Schema::table('stock_movements', fn (Blueprint $t) => $t->dropIndex('stock_movements_item_day_index'));
    }
};
