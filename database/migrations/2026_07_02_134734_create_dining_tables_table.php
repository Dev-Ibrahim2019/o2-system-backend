<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

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
            $table->unsignedBigInteger('current_order_id')->nullable();
            $table->string('status', 30)->default('AVAILABLE');
            $table->timestamps();

            $table->unique(['dining_zone_id', 'table_number']);
        });

        // CHECK constraint for status - MySQL compatible
        try {
            DB::statement("ALTER TABLE dining_tables DROP CHECK dining_tables_status_check");
        } catch (\Throwable $e) {
            // Constraint may not exist
        }

        DB::statement("ALTER TABLE dining_tables ADD CONSTRAINT dining_tables_status_check CHECK (status IN ('AVAILABLE', 'OCCUPIED', 'PAYMENT_PENDING', 'PAID', 'RESERVED', 'CLEANING', 'HAS_ORDER', 'PENDING_CONFIRMATION'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            DB::statement("ALTER TABLE dining_tables DROP CHECK dining_tables_status_check");
        } catch (\Throwable $e) {
            // Constraint may not exist
        }
        Schema::dropIfExists('dining_tables');
    }
};
