<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ══════════════════════════════════════════════════════════════
 * MIGRATION: Remove account_id from suppliers
 * ══════════════════════════════════════════════════════════════
 *
 * لم نعد نستخدم حسابات GL منفصلة لكل مورد.
 * جميع أرصدة الموردين تُحسب عبر subledger:
 *   entries.subledger_type = 'supplier'
 *   entries.subledger_id   = {supplier_id}
 *
 * حساب 2110 (Accounts Payable) هو حساب التحكم الوحيد.
 * account_id الموجود في جدول الموردين لم يعد مستخدماً.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            // إزالة المفتاح الخارجي أولاً
            $table->dropForeign(['account_id']);
            // إزالة العمود
            $table->dropColumn('account_id');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->foreignId('account_id')
                ->nullable()
                ->constrained('accounts')
                ->nullOnDelete();
        });
    }
};
