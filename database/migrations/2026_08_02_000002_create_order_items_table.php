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
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')
                ->constrained()
                ->cascadeOnDelete();

            // المنتج
            $table->foreignId('item_id');
            $table->foreignId('department_id')->nullable();

            $table->string('item_name');
            $table->string('item_name_ar')->nullable();

            $table->decimal('quantity', 10, 2)->default(1);

            // السعر وقت الطلب
            $table->decimal('price', 15, 2);
            $table->decimal('original_price', 15, 2)->nullable();
            $table->decimal('final_price', 15, 2)->nullable();

            // الخصم
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('discount_percent', 8, 2)->nullable();
            $table->foreignId('discount_id')->nullable()
                ->constrained('discounts')->nullOnDelete();
            $table->string('discount_apply_strategy', 30)->nullable();

            // الضريبة
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->decimal('tax_amount', 15, 3)->nullable();

            // الإجمالي
            $table->decimal('total', 15, 2);

            // حالة العنصر
            $table->enum('status', [
                'pending',
                'preparing',
                'ready',
                'served',
                'cancelled'
            ])->default('pending');

            $table->text('notes')->nullable();

            $table->timestamp('sent_to_kitchen_at')->nullable();

            $table->boolean('is_printed_direct')->default(false);
            $table->boolean('is_takeaway')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
