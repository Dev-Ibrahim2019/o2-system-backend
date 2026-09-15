<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// يضيف 'closed' كقيمة status صريحة (كانت "الإغلاق" محسوبة ضمنيًا فقط من served/DELIVERED+مدفوع
// بالكامل، بدون قيمة مخزّنة فعليًا) + أعمدة تتبّع الإغلاق/إعادة الفتح. الشرط الفعلي لضبط 'closed'
// يبقى نفسه تمامًا (اكتمال العملية + دفع كامل) — فقط صار يُكتب صراحة بدل الاستنتاج الضمني، عبر
// OrderStatusService المركزية بدل استعلامات متفرقة.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('orders', 'closed_by')) {
                $table->unsignedBigInteger('closed_by')->nullable()->after('closed_at');
            }
            if (! Schema::hasColumn('orders', 'reopened_at')) {
                $table->timestamp('reopened_at')->nullable()->after('closed_by');
            }
            if (! Schema::hasColumn('orders', 'reopen_reason')) {
                $table->text('reopen_reason')->nullable()->after('reopened_at');
            }
        });

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        try {
            DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_check');
        } catch (\Throwable $e) {
            // قد لا يوجد القيد بهذا الاسم بكل البيئات
        }
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending', 'pending_confirmation', 'confirmed', 'in_progress', 'ready', 'served', 'paid', 'cancelled', 'pending_payment', 'scheduled', 'closed', 'PENDING_PAYMENT', 'PREPARATION', 'ASSEMBLING', 'READY_FOR_DELIVERY', 'OUT_FOR_DELIVERY', 'CANCELLATION_REQUESTED', 'DELIVERED', 'FAILED_DELIVERY', 'CANCELLED'))");
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['reopen_reason', 'reopened_at', 'closed_by', 'closed_at'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
