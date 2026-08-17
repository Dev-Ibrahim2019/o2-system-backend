<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'table_number')) {
                $table->string('table_number')->nullable()->after('status');
            }
            if (!Schema::hasColumn('orders', 'customer_address_id')) {
                $table->foreignId('customer_address_id')->nullable()->constrained('customer_addresses')->nullOnDelete();
            }
            if (!Schema::hasColumn('orders', 'delivery_address_snapshot')) {
                $table->json('delivery_address_snapshot')->nullable();
            }
            if (!Schema::hasColumn('orders', 'discount_value')) {
                $table->decimal('discount_value', 15, 2)->default(0)->after('subtotal');
            }
            if (!Schema::hasColumn('orders', 'discount_type')) {
                $table->string('discount_type')->nullable()->after('discount_value');
            }
            if (!Schema::hasColumn('orders', 'total')) {
                $table->decimal('total', 15, 2)->default(0)->after('discount_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'table_number',
                'customer_address_id',
                'delivery_address_snapshot',
                'discount_value',
                'discount_type',
                'total',
            ]);
        });
    }
};
