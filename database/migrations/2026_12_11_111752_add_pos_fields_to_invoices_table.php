<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * إضافة حقول نقاط البيع وتفاصيل الفتح والإغلاق إلى جدول الفواتير
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // ── تفاصيل نقطة البيع (POS) ──
            $table->foreignId('pos_register_id')
                ->nullable()
                ->constrained('pos_registers')
                ->nullOnDelete();
            $table->string('pos_code')->nullable()->after('pos_register_id');
            $table->string('pos_name')->nullable()->after('pos_code');

            // ── المستخدم الذي فتح الفاتورة ──
            $table->foreignId('opened_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->dateTime('opened_at')->nullable()->after('opened_by');

            // ── المستخدم الذي أغلق الفاتورة ──
            $table->foreignId('closed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->dateTime('closed_at')->nullable()->after('closed_by');

            // ── عملة الفاتورة ورقم الحساب ──
            $table->string('currency', 3)->default('ILS')->after('closed_at');
            $table->string('account_number')->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pos_register_id');
            $table->dropConstrainedForeignId('opened_by');
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn([
                'pos_code',
                'pos_name',
                'opened_at',
                'closed_at',
                'currency',
                'account_number',
            ]);
        });
    }
};
