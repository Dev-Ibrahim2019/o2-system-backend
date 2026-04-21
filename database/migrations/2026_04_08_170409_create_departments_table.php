<?php
// database/migrations/2026_xx_xx_create_departments_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('departments')
                ->nullOnDelete();
            $table->enum('type', ['department', 'section', 'unit'])->default('department');
            $table->boolean('is_central')->default(false);
            $table->string('shortName')->nullable();
            $table->string('icon')->nullable();
            $table->string('color')->default('#ef4444');
            $table->boolean('is_active')->default(true);
            $table->string('stationNumber')->nullable();
            $table->integer('defaultPrepTime')->default(0);
            $table->integer('maxConcurrentOrders')->default(10);
            $table->boolean('hasKds')->default(false);
            $table->boolean('autoPrintTicket')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
