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

            $table->foreignId('dining_table_id')->nullable()->constrained('dining_tables')->nullOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cashier_id')
                ->nullable()
                ->references('id')->on('employees')->nullOnDelete();

            // نوع الطلب: dine_in | takeaway
            $table->enum('order_type', ['dine_in', 'takeaway', 'delivery'])->default('dine_in');

            // حالة الطلب الكلية
            $table->enum('status', [
                'pending',
                'pending_confirmation',
                'confirmed',
                'in_progress',
                'ready',
                'served',
                'pending_payment',
                'paid',
                'cancelled',
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

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
