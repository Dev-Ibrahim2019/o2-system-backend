<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // delivery_zone_id: ربط بمنطقة التوصيل في dining_zones
            if (!Schema::hasColumn('orders', 'delivery_zone_id')) {
                $table->foreignId('delivery_zone_id')
                    ->nullable()
                    ->constrained('dining_zones')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'delivery_zone_id')) {
                $table->dropForeign(['delivery_zone_id']);
                $table->dropColumn('delivery_zone_id');
            }
        });
    }
};
