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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            // رقم الفاتورة
            $table->string('number')->unique();
            $table->string('type', 30)->nullable();

            // الطلب المرتبط
            $table->foreignId('order_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // الزبون
            $table->foreignId('customer_id')->nullable();
            $table->string('customer_name')->nullable();

            // Entity (polymorphic)
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();

            // الفرع
            $table->foreignId('branch_id')->nullable();

            // الحالة - VARCHAR for PostgreSQL compatibility
            $table->string('status', 30)->default('draft');

            // طريقة الدفع - VARCHAR for PostgreSQL compatibility
            $table->string('payment_method', 50)->nullable();

            // العملة
            $table->string('currency', 10)->default('ILS');

            // المبالغ
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 4)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('remaining_amount', 12, 2)->default(0);

            // تاريخ الفاتورة
            $table->dateTime('invoice_date');
            $table->date('due_date')->nullable();
            $table->date('delivery_date')->nullable();
            $table->date('expected_payment_date')->nullable();
            $table->date('supply_date')->nullable();

            // POS fields
            $table->foreignId('pos_register_id')
                ->nullable()
                ->constrained('pos_registers')
                ->nullOnDelete();
            $table->string('pos_code')->nullable();
            $table->string('pos_name')->nullable();

            // Open/Close tracking
            $table->foreignId('opened_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->dateTime('opened_at')->nullable();
            $table->foreignId('closed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->dateTime('closed_at')->nullable();

            // Approval
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();

            // Reference
            $table->string('reference_number', 100)->nullable();
            $table->string('account_number')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
