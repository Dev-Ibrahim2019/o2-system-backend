<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders')) {
            return;
        }

        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('dining_table_id')
                ->nullable()
                ->constrained('dining_tables')
                ->nullOnDelete();

            // معلومات الطلب
            $table->string('order_number')->unique();
            $table->string('reference_number')->nullable();
            $table->string('barcode')->nullable();

            // نوع الطلب
            $table->enum('order_type', [
                'dine_in',
                'takeaway',
                'delivery',
            ])->default('dine_in');

            // الحالة الرئيسية
            $table->enum('status', [
                'PENDING_PAYMENT',
                'PREPARATION',
                'READY',
                'SERVED',
                'CANCELLED',
            ])->default('PENDING_PAYMENT');

            // حالة فرعية (اختياري)
            $table->string('sub_status')->nullable();

            // معلومات الطاولة
            $table->string('table_number')->nullable();

            // معلومات العميل
            $table->string('customer_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('customer_phone')->nullable();

            // المبالغ
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('change_amount', 15, 2)->default(0);
            $table->decimal('balance_amount', 15, 2)->default(0);

            // معلومات الدفع
            $table->string('payment_status')->default('UNPAID');
            $table->string('transaction_id')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->text('note')->nullable();

            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->text('cancellation_reason')->nullable();

            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index('customer_id');
            $table->index('branch_id');
            $table->index('status');
            $table->index('order_number');
            $table->index('cashier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};