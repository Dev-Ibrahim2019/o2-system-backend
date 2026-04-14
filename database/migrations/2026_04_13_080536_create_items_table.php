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
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')
                ->nullable()
                ->constrained('group_id')
                ->nullOnDelete();
            $table->string('image')->nullable();
            $table->string('name');
            $table->string('unit');              // كغ، حبة، لتر، غ ...
            $table->enum('base_type', ['sellable', 'ingredient', 'raw_material']);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
