<?php
// database/migrations/2026_04_28_000001_create_orders_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            // رقم الطلب المعروض للكاشير (تسلسلي يومي)
            $table->string('order_number')->unique();

            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cashier_id')
                ->nullable()
                ->references('id')->on('employees')->nullOnDelete();

            // نوع الطلب: dine_in | takeaway
            $table->enum('order_type', ['dine_in', 'takeaway'])->default('dine_in');

            // حالة الطلب الكلية
            $table->enum('status', [
                'pending',      // حُفظ ولم يُرسل بعد
                'confirmed',    // أُرسل للأقسام
                'in_progress',  // الأقسام تعمل عليه
                'ready',        // جاهز للتسليم
                'served',       // سُلِّم للزبون
                'paid',         // مدفوع ومغلق
                'cancelled',    // ملغي
            ])->default('pending');

            // معلومات الطاولة (للمحلي)
            $table->string('table_number')->nullable();

            // معلومات الزبون
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();

            // ملاحظة الفاتورة
            $table->text('note')->nullable();

            // المبالغ
            $table->decimal('subtotal', 10, 3)->default(0);
            $table->decimal('discount_value', 10, 3)->default(0);
            $table->enum('discount_type', ['amount', 'percent'])->default('amount');
            $table->decimal('discount_amount', 10, 3)->default(0); // المبلغ الفعلي للخصم
            $table->decimal('total', 10, 3)->default(0);

            // طريقة الدفع
            $table->enum('payment_method', ['cash', 'credit_card', 'wallet'])->nullable();
            $table->string('reference_number')->nullable()->unique();
            // تاريخ الدفع الفعلي
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
