<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_invoice_id')->constrained()->cascadeOnDelete();

            // ── Payment Details ──
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('method', ['cash', 'credit_card', 'bank_transfer', 'app', 'account'])->default('cash');
            $table->decimal('amount', 15, 4)->default(0);
            $table->string('reference_number')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->text('notes')->nullable();

            // ── Entity Tracking (for account payments) ──
            $table->string('entity_type')->nullable();
            $table->foreignId('entity_id')->nullable();
            $table->string('subledger_type')->nullable();
            $table->foreignId('subledger_id')->nullable();

            // ── Accounting ──
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index('sales_invoice_id');
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_payments');
    }
};
