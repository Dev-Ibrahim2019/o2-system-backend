<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();

            // رقم القيد — يُولَّد تلقائياً (JV-YYYYMMDD-XXXX)
            $table->string('transaction_number')->unique()->nullable();

            $table->date('date');

            // رقم مرجعي خارجي (رقم الفاتورة، رقم الطلب، إلخ)
            $table->string('reference')->nullable();

            // نوع القيد
            $table->enum('type', [
                'sale',           // مبيعات
                'purchase',       // مشتريات
                'salary',         // رواتب
                'expense',        // مصروف
                'receipt',        // قبض
                'payment',        // دفع
                'journal',        // قيد يومية عام
                'opening',        // رصيد افتتاحي
                'adjustment',     // تسوية
            ])->default('journal');

            // ✅ حالة القيد
            $table->enum('status', [
                'draft',    // مسودة — يمكن تعديله
                'posted',   // مرحَّل — لا يمكن تعديله
                'cancelled', // ملغي
            ])->default('draft');

            $table->text('description')->nullable();

            // ✅ constrained() صحيح (كانت ناقصة)
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // polymorphic relation

            $table->nullableMorphs('source');

            // ✅ تاريخ الترحيل
            $table->timestamp('posted_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
