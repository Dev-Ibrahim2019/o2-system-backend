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
        Schema::create('branches', function (Blueprint $table) {
            $table->id('id');
            $table->string('name');
<<<<<<< Updated upstream
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('code');
            $table->boolean('isMainBranch');
            $table->time('closingTime');
            $table->time('openingTime');
=======
            $table->string('location')->nullable();
            $table->boolean('is_active')->default(true);
>>>>>>> Stashed changes
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
<<<<<<< Updated upstream
    {       
=======
    {
>>>>>>> Stashed changes
        Schema::dropIfExists('branches');
    }
};
