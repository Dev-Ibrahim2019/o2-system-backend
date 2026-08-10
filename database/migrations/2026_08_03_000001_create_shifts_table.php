<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opened_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->date('date'); // تاريخ اليومية
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();

            $table->enum('status', ['open', 'closed'])->default('open');

            $table->decimal('opening_balance', 10, 2)->default(0);
            $table->decimal('closing_balance', 10, 2)->nullable();
            $table->decimal('total_sales', 10, 2)->default(0);

            $table->timestamps();

            // فهرس فريد: شفت واحد مفتوح فقط لكل فرع في التاريخ
            $table->unique(['branch_id', 'date', 'status'], 'shift_branch_date_status_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
