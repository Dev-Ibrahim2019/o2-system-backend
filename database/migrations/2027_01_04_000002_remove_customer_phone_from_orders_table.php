<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ينقل رقم جوال العميل من orders إلى invoices.
     * الخطوة 2: حذف العمود من orders بعد ترحيل بياناته (راجع migration
     * add_customer_phone_to_invoices_table التي تُشغَّل قبل هذه).
     */
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'customer_phone')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('customer_phone');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('orders', 'customer_phone')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('customer_phone')->nullable();
            });
        }
    }
};
