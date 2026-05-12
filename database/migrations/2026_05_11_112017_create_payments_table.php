<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            // الفاتورة المرتبطة
            $table->foreignId('invoice_id')
                ->constrained()
                ->cascadeOnDelete();

            // رقم عملية الدفع
            $table->string('number')->unique();

            // نوع الدفع
            $table->enum('method', [
                'cash',
                'card',
                'bank',
                'wallet',
                'account',
                'mixed'
            ]);

            // المبلغ
            $table->decimal('amount', 15, 2);

            // وقت الدفع
            $table->dateTime('paid_at');

            // ملاحظات
            $table->text('notes')->nullable();

            // الفرع
            $table->foreignId('branch_id')->nullable();

            // المستخدم
            $table->foreignId('user_id')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
