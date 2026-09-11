<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// تتبّع التنفيذ التلقائي للطلبات المجدولة (orders:execute-scheduled) — executed_at علامة
// idempotency لمنع التنفيذ المزدوج، execution_attempts/execution_failed_reason لتمييز الفشل
// العابر (قابل لإعادة المحاولة) عن فشل يحتاج تدخل بشري (مثلاً طلب غير مدفوع).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'executed_at')) {
                $table->timestamp('executed_at')->nullable()->after('scheduled_at');
            }
            if (! Schema::hasColumn('orders', 'execution_attempts')) {
                $table->unsignedTinyInteger('execution_attempts')->default(0)->after('executed_at');
            }
            if (! Schema::hasColumn('orders', 'execution_failed_reason')) {
                $table->string('execution_failed_reason')->nullable()->after('execution_attempts');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['execution_failed_reason', 'execution_attempts', 'executed_at'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
