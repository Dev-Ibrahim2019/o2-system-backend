<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_routes', function (Blueprint $table) {
            $table->foreignId('pos_register_id')->nullable()->constrained('pos_registers')->onDelete('cascade');
            $table->foreignId('hospitality_device_id')->nullable()->constrained('hospitality_devices')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('print_routes', function (Blueprint $table) {
            $table->dropForeign(['pos_register_id']);
            $table->dropForeign(['hospitality_device_id']);
            $table->dropColumn(['pos_register_id', 'hospitality_device_id']);
        });
    }
};
