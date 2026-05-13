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
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')
                ->constrained()
                ->cascadeOnDelete();

            // المنتج
            $table->foreignId('item_id');

            // نحفظ الاسم حتى لو تغير لاحقاً
            $table->string('item_name');

            $table->decimal('quantity', 10, 2)->default(1);

            // السعر وقت الفاتورة
            $table->decimal('price', 15, 2);

            $table->decimal('total', 15, 2);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
