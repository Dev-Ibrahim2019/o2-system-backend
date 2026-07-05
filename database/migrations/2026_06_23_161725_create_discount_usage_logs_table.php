<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasDiscounts = Schema::hasTable('discounts');
        $hasInvoices = Schema::hasTable('invoices');
        $hasInvoiceItems = Schema::hasTable('invoice_items');
        $hasOrders = Schema::hasTable('orders');

        Schema::create('discount_usage_logs', function (Blueprint $table) use ($hasDiscounts, $hasInvoices, $hasInvoiceItems, $hasOrders) {
            $table->id();
            $table->foreignId('discount_id')->constrained('discounts')->cascadeOnDelete();
if ($hasInvoices) {
    $table->foreignId('invoice_id')
        ->nullable()
        ->constrained('invoices')
        ->nullOnDelete();
} else {
    $table->unsignedBigInteger('invoice_id')->nullable();
}

if ($hasInvoiceItems) {
    $table->foreignId('invoice_item_id')
        ->nullable()
        ->constrained('invoice_items')
        ->nullOnDelete();
} else {
    $table->unsignedBigInteger('invoice_item_id')->nullable();
}

if ($hasOrders) {
    $table->foreignId('order_id')
        ->nullable()
        ->constrained('orders')
        ->nullOnDelete();
} else {
    $table->unsignedBigInteger('order_id')->nullable();
}

// معلومات العميل/الموظف/المورد عند الاستخدام            $table->string('entity_type', 50)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->decimal('original_price', 15, 3)->default(0);
            $table->decimal('discount_amount', 15, 3)->default(0);
            $table->decimal('final_price', 15, 3)->default(0);
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->index(['discount_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_usage_logs');
    }
};
