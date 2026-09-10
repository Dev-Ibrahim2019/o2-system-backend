<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ينقل رقم جوال العميل من orders إلى invoices.
     * الخطوة 1: إضافة العمود + تعبئته من الطلبات المرتبطة (قبل حذفه من orders).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('invoices', 'customer_phone')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->string('customer_phone')->nullable()->after('customer_name');
            });
        }

        if (Schema::hasColumn('orders', 'customer_phone')) {
            DB::table('invoices')
                ->join('orders', 'orders.id', '=', 'invoices.order_id')
                ->whereNotNull('orders.customer_phone')
                ->orderBy('invoices.id')
                ->select('invoices.id', 'orders.customer_phone')
                ->chunk(500, function ($rows) {
                    foreach ($rows as $row) {
                        DB::table('invoices')
                            ->where('id', $row->id)
                            ->update(['customer_phone' => $row->customer_phone]);
                    }
                });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('invoices', 'customer_phone')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn('customer_phone');
            });
        }
    }
};
