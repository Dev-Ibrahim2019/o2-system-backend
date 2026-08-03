<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->string('reference_number');
            $table->string('normalized_reference_number');
            $table->decimal('amount', 15, 3);
            $table->string('status', 24)->default('confirmed')->index();
            $table->string('idempotency_key', 100)->unique();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->unique(
                ['payment_method_id', 'normalized_reference_number'],
                'payment_confirmations_method_normalized_reference_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_confirmations');
    }
};