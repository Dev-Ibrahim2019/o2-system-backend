<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('customer_complaints')) {
            return;
        }

        Schema::create('customer_complaints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->text('description');

            $table->string('type', 50)->default('other');
            $table->string('priority', 20)->default('normal');
            $table->string('status', 30)->default('new');

            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->string('resolution_result', 50)->nullable();

            $table->string('severity', 20)->default('info');
            $table->boolean('is_sensitive')->default(false);
            $table->boolean('show_alert')->default(true);

            $table->string('branch_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('customer_id');
            $table->index('status');
            $table->index('priority');
            $table->index(['customer_id', 'status']);
        });

        if (Schema::hasTable('orders')) {
            Schema::table('customer_complaints', function (Blueprint $table) {
                $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_complaints');
    }
};
