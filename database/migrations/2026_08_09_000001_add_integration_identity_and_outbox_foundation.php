<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('public_ref', 40)->nullable()->unique()->after('id');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('external_ref', 40)->nullable()->unique()->after('id');
        });

        Schema::create('integration_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('outbox_ref', 40)->unique();
            $table->string('event_type', 100)->index();
            $table->string('aggregate_type', 50);
            $table->string('aggregate_ref', 40)->index();
            $table->json('payload');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->timestamp('occurred_at');
            $table->timestamp('available_at')->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['aggregate_type', 'aggregate_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_outbox');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['external_ref']);
            $table->dropColumn('external_ref');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['public_ref']);
            $table->dropColumn('public_ref');
        });
    }
};
