<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_invoice_id')->constrained()->cascadeOnDelete();

            // ── Item Reference ──
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_name');
            $table->text('description')->nullable();

            // ── Quantity & Pricing ──
            $table->decimal('quantity', 15, 4)->default(1);
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('discount', 15, 4)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);

            // ── Tax ──
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 4)->default(0);

            // ── Totals ──
            $table->decimal('total_before_tax', 15, 4)->default(0);
            $table->decimal('total', 15, 4)->default(0);

            // ── Accounting ──
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            // ── Tracking (ZATCA / Cost Center) ──
            $table->string('tracking_name')->nullable();
            $table->string('tracking_option')->nullable();

            $table->timestamps();

            $table->index('sales_invoice_id');
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_items');
    }
};
