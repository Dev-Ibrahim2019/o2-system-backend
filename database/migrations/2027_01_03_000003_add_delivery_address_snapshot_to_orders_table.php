<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')
            || Schema::hasColumn('orders', 'delivery_address_snapshot')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->json('delivery_address_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')
            || ! Schema::hasColumn('orders', 'delivery_address_snapshot')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('delivery_address_snapshot');
        });
    }
};
