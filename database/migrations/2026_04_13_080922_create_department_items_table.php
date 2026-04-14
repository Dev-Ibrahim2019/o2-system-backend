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
        Schema::create('department_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['sale', 'ingredient', 'raw_material']);
            $table->decimal('price', 10, 3)->nullable();  // للـ sale فقط
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['department_id', 'item_id', 'role'], 'dept_item_role_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('department_items');
    }
};
