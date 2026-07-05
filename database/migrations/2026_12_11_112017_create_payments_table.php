<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices')) {
            Schema::create('payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
                $table->string('number')->unique();
                $table->enum('method', ['cash', 'card', 'bank', 'wallet', 'account', 'mixed']);
                $table->decimal('amount', 15, 2);
                $table->dateTime('paid_at');
                $table->text('notes')->nullable();
                $table->foreignId('branch_id')->nullable();
                $table->foreignId('user_id')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
