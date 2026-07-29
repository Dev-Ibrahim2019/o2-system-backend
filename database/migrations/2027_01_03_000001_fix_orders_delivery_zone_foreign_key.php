<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')
            || ! Schema::hasTable('delivery_zones')
            || ! Schema::hasColumn('orders', 'delivery_zone_id')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_delivery_zone_id_foreign');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('delivery_zone_id')
                ->references('id')
                ->on('delivery_zones')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')
            || ! Schema::hasTable('dining_zones')
            || ! Schema::hasColumn('orders', 'delivery_zone_id')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_delivery_zone_id_foreign');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('delivery_zone_id')
                ->references('id')
                ->on('dining_zones')
                ->nullOnDelete();
        });
    }
};
