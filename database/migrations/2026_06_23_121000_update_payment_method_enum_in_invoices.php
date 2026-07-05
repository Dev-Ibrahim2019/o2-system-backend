<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تغيير payment_method من ENUM إلى VARCHAR لدعم جميع أنواع الدفع.
     * يُتخطى على SQLite وعند عدم وجود الجدول (يُنشأ كـ VARCHAR في migration الإنشاء).
     */
    public function up(): void
    {
        if (! Schema::hasTable('invoices')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('payment_method', 50)
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoices') || Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('payment_method', 50)->nullable()->change();
        });
    }
};
