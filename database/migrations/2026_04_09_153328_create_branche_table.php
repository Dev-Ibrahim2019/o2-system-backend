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
        Schema::create('branche', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('city');
            $table->string('address');
            $table->string('phone')->nullable();
            $table->enum('status', ['ACTIVE', 'INACTIVE', 'MAINTENANCE', 'BUSY'])->default('ACTIVE');
            $table->foreignId('parent_id')->nullable()->constrained('branche')->onDelete('set null');
            $table->string('google_map_url')->nullable();
            $table->string('email')->nullable();
            $table->string('whatsapp')->nullable();
            $table->time('opening_time')->nullable();
            $table->time('closing_time')->nullable();
            $table->boolean('isMainBranch')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branche');
    }
};
