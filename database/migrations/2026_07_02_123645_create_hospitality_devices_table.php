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
        Schema::create('hospitality_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->string('name');
            $table->string('device_uuid')->nullable()->unique();
            $table->string('activation_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->enum('status', ['ACTIVE', 'INACTIVE', 'PENDING_ACTIVATION', 'REVOKED'])
                ->default('PENDING_ACTIVATION');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hospitality_devices');
    }
};
