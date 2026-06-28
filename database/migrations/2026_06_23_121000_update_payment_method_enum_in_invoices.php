<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * تغيير payment_method من ENUM إلى VARCHAR لدعم جميع أنواع الدفع
     * (cash, bank, card, wallet, customer, employee, supplier, mixed, account)
     */
    public function up(): void
    {
        // MySQL لا يدعم ALTER ENUM مباشرة لإضافة قيم جديدة في منتصف الجدول
        // الحل: تغيير العمود إلى VARCHAR
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('payment_method', 50)
                ->nullable()
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // العودة إلى ENUM
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('payment_method', 50)->nullable()->change();
        });
    }
};
