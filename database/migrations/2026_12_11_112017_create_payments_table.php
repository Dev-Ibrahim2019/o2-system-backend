<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
            $table->string('method', 50);

            // المبلغ
            $table->decimal('amount', 15, 2);

            // Entity fields (polymorphic)
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('subledger_type')->nullable();
            $table->unsignedBigInteger('subledger_id')->nullable();

            // Payment method reference
            $table->foreignId('payment_method_id')
                ->nullable()
                ->constrained('payment_methods')
                ->nullOnDelete();

            $table->string('reference_number', 255)->nullable();

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

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
