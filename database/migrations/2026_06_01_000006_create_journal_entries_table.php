<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // نُضيف الحقول المفقودة للجدول الحالي
        Schema::table('transactions', function (Blueprint $table) {

            // رقم مرجعي للعملية الأصل (للعكس — Reversals)
            if (! Schema::hasColumn('transactions', 'reversal_of_id')) {
                $table->foreignId('reversal_of_id')
                    ->nullable()
                    ->after('reference')
                    ->constrained('transactions')
                    ->nullOnDelete();
            }

            // هل هذا قيد عكس؟
            if (! Schema::hasColumn('transactions', 'is_reversal')) {
                $table->boolean('is_reversal')->default(false)->after('reversal_of_id');
            }

            // الفترة المحاسبية (Accounting Period) — لمنع التعديل على فترات مغلقة
            if (! Schema::hasColumn('transactions', 'period_id')) {
                $table->unsignedBigInteger('period_id')->nullable()->after('is_reversal');
            }

            // العملة والسعر — multi-currency
            if (! Schema::hasColumn('transactions', 'currency')) {
                $table->char('currency', 3)->default('ILS')->after('period_id');
            }
            if (! Schema::hasColumn('transactions', 'exchange_rate')) {
                $table->decimal('exchange_rate', 15, 6)->default(1)->after('currency');
            }

            // المعتمد والوقت
            if (! Schema::hasColumn('transactions', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')
                    ->nullable()
                    ->after('user_id');
            }
            if (! Schema::hasColumn('transactions', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'reversal_of_id',
                'is_reversal',
                'period_id',
                'currency',
                'exchange_rate',
                'approved_by',
                'approved_at',
            ]);
        });
    }
};
