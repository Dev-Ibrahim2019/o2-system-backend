<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** رقم مرجع اختياري للمستند (رقم دفتر/طلب القسم...) — يظهر في القائمة ويُبحث به. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_documents', function (Blueprint $table) {
            $table->string('reference_no', 64)->nullable()->after('supplier_invoice_no');
            $table->index('reference_no');
        });
    }

    public function down(): void
    {
        Schema::table('stock_documents', function (Blueprint $table) {
            $table->dropIndex(['reference_no']);
            $table->dropColumn('reference_no');
        });
    }
};
