<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope', 100);
            $table->string('key', 100);
            $table->string('request_hash', 64);
            $table->string('status', 32)->default('pending')->index();
            $table->nullableMorphs('resource');
            $table->json('response')->nullable();
            $table->unsignedSmallInteger('response_status')->default(200);
            $table->timestamps();

            $table->unique(['scope', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
    }
};
