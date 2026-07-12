<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('complaint_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained('customer_complaints')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('action', 50); // created, status_changed, note_added, call_made, resolved, reopened
            $table->text('notes')->nullable();

            $table->string('old_status', 30)->nullable();
            $table->string('new_status', 30)->nullable();

            $table->string('followup_type', 30)->default('note'); // note, call, action, system

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index('complaint_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_followups');
    }
};
