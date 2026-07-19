<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id');
            $table->string('item_name');
            $table->text('description')->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('price', 15, 2);
            $table->decimal('original_price', 15, 3)->nullable();
            $table->decimal('subtotal', 15, 3)->nullable();
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('discount', 15, 4)->default(0);
            $table->decimal('discount_amount', 15, 3)->default(0);
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->unsignedBigInteger('discount_id')->nullable();
            $table->string('discount_apply_strategy', 30)->nullable();
            $table->decimal('final_price', 15, 3)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 4)->default(0);
            $table->decimal('total_before_tax', 15, 4)->default(0);
            $table->decimal('total', 15, 2);
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
