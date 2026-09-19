<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// orders.driver_id (FK -> employees, nullable) already exists — كان أضيف بـ migration قديمة
// (2026_09_28_000004) لميزة لم تُكمل، وغير مستخدم بأي كود حالياً (تحقّقنا). هذه الـ migration
// تضيف فقط الأعمدة الفعليًا الناقصة لتتبّع دورة تعيين/تسليم التوصيل.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'delivery_assigned_at')) {
                $table->timestamp('delivery_assigned_at')->nullable()->after('driver_id');
            }
            if (! Schema::hasColumn('orders', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable()->after('delivery_assigned_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'delivered_at')) {
                $table->dropColumn('delivered_at');
            }
            if (Schema::hasColumn('orders', 'delivery_assigned_at')) {
                $table->dropColumn('delivery_assigned_at');
            }
        });
    }
};
