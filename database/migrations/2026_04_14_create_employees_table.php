<?php
// database/migrations/2026_04_14_create_employees_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            $table->string('employeeId')->unique()->nullable();
            $table->string('name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('nationalId')->nullable();
            $table->date('dob')->nullable();
            $table->string('image')->nullable();

            // ← nullable لأن branches قد لا يكون موجوداً بعد
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();

            $table->string('jobTitleId')->nullable();
            $table->string('typeId')->nullable();
            $table->string('managerId')->nullable();
            $table->date('hireDate');
            $table->decimal('salary', 10, 2)->nullable();

            $table->string('role')->default('EMPLOYEE');
            $table->enum('status', ['ACTIVE', 'ON_LEAVE', 'TERMINATED', 'SUSPENDED', 'RESIGNED'])->default('ACTIVE');
            $table->string('username')->nullable()->unique();
            $table->string('password')->nullable();
            $table->string('pin', 10)->nullable();
            $table->json('permissions')->nullable();
            $table->text('notes')->nullable();

            $table->decimal('rating', 3, 1)->default(5.0);
            $table->json('performance')->nullable();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};