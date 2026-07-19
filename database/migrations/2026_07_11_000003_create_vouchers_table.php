<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();

            // ── Identification ──
            $table->string('number')->unique();
            $table->enum('type', ['receipt', 'payment'])->comment('receipt=سند قبض, payment=سند صرف');

            // ── Party ──
            $table->enum('entity_type', ['customer', 'supplier']);
            $table->unsignedBigInteger('entity_id');
            $table->string('entity_name')->nullable();

            // ── Financial ──
            $table->decimal('amount', 15, 4)->default(0);
            $table->decimal('balance_before', 15, 4)->default(0)->comment('Remaining balance before payment');
            $table->string('currency', 10)->default('ILS');

            // ── Payment ──
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->string('payment_method_name')->nullable();
            $table->string('reference_number')->nullable()->comment('Cheque/transfer reference');

            // ── Context ──
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->comment('Related shift');
            $table->foreignId('accounting_day_id')->nullable()->comment('Related accounting day');
            $table->date('voucher_date');

            // ── Status ──
            $table->enum('status', ['draft', 'active', 'cancelled', 'reversed'])->default('draft');
            $table->text('notes')->nullable();

            // ── Audit ──
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // ── Indexes ──
            $table->index(['type', 'status']);
            $table->index(['entity_type', 'entity_id']);
            $table->index('voucher_date');
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
