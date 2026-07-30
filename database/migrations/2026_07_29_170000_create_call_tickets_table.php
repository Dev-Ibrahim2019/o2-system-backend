<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('external_call_id')->nullable()->unique();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('linked_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('direction', 20)->default('inbound');
            $table->string('status', 30)->default('open');
            $table->string('incoming_phone', 40);
            $table->string('normalized_phone', 40)->index();
            $table->string('source', 30)->default('manual');
            $table->string('disposition', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_tickets');
    }
};
