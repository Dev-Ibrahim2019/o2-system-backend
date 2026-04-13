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
            $table->string('nameAr')->nullable();
            $table->string('shortName')->nullable();
            $table->string('icon')->nullable();
            $table->string('color')->default('#ef4444');
            $table->enum('type', ['KITCHEN', 'BAR', 'GRILL', 'PASTRY', 'OTHER'])->default('KITCHEN');
            $table->enum('status', ['ACTIVE', 'BUSY', 'INACTIVE'])->default('ACTIVE');
            $table->string('location')->nullable();
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
