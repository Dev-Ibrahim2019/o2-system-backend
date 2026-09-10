<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's standard database-notifications table.
 *
 * The project ships the Notifiable trait on User but never had the table, so
 * every ->notify() would have thrown. This is the stock schema from
 * `artisan notifications:table` — a UUID primary key, a polymorphic
 * notifiable, the JSON payload and a nullable read_at.
 *
 * First consumer: complaint activity (an agent takes / resolves / cancels a
 * complaint, or a manager reassigns one). Nothing here is complaint-specific,
 * so any later notification type reuses it as-is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
