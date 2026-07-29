<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')
            || ! Schema::hasTable('customer_addresses')
            || Schema::hasColumn('orders', 'customer_address_id')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('customer_address_id')
                ->nullable()
                ->constrained('customer_addresses')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')
            || ! Schema::hasColumn('orders', 'customer_address_id')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_address_id');
        });
    }
};
