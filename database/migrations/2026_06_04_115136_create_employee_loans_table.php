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
        Schema::create('employee_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->decimal('amount', 10, 2);
            $table->date('date_granted');
            $table->date('repayment_date')->nullable();
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->enum('status', ['pending', 'repaid', 'partially_repaid', 'cancelled'])->default('pending');
            $table->text('notes')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->onDelete('set null');
            $table->timestamps();
        });
        // Add a unique constraint to prevent duplicate loans for the same employee on the same date
        // $table->unique(['employee_id', 'date_granted']); // Consider if this is needed, or if multiple loans per day are allowed


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_loans');
    }
};