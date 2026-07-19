<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained('vouchers')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->decimal('amount', 15, 4)->default(0)->comment('Amount allocated to this invoice');
            $table->timestamps();

            $table->index(['voucher_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_allocations');
    }
};
