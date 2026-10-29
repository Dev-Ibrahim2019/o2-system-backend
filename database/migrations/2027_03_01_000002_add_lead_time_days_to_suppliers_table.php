<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مدة التوريد بالأيام — تُستخدم لاحقاً في حساب نقطة إعادة الطلب.
 * عمود جديد قابل للفراغ، فلا يتأثر أي مسار قائم للموردين.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedSmallInteger('lead_time_days')->nullable()->after('payment_terms');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('lead_time_days');
        });
    }
};
