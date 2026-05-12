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

            $table->string('item_name');

            $table->decimal('quantity', 10, 2)->default(1);

            // السعر وقت الطلب
            $table->decimal('price', 15, 2);

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
