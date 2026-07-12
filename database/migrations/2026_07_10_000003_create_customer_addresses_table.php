<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('customer_addresses')) {
            Schema::create('customer_addresses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $table->string('label', 50)->default('منزل'); // home, work, branch, other
                $table->string('city', 100)->nullable();
                $table->string('area', 100)->nullable();
                $table->string('district', 100)->nullable();
                $table->string('street', 255)->nullable();
                $table->string('landmark', 255)->nullable();
                $table->string('building_no', 50)->nullable();
                $table->string('floor', 20)->nullable();
                $table->string('apartment', 20)->nullable();
                $table->text('delivery_notes')->nullable();
                $table->string('phone', 30)->nullable();
                $table->string('map_url')->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
    }
};
