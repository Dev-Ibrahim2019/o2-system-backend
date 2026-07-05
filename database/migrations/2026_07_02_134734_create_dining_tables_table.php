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
        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dining_zone_id')->constrained('dining_zones')->onDelete('cascade');
            $table->string('table_number', 20); // A1, A2, B1, etc.
            $table->string('qr_code', 100)->unique(); // Unique QR code
            $table->string('qr_url', 500); // URL for QR code
            $table->integer('capacity')->default(4);
            $table->enum('status', ['AVAILABLE', 'OCCUPIED', 'PAYMENT_PENDING', 'PAID', 'RESERVED', 'CLEANING'])->default('AVAILABLE');
            $table->timestamps();

            $table->unique(['dining_zone_id', 'table_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dining_tables');
    }
};
