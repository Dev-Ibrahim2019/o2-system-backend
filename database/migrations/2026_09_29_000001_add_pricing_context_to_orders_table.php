<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'customer_id')) {
                $table->unsignedBigInteger('customer_id')->nullable()->after('customer_phone');
            }
            if (! Schema::hasColumn('orders', 'employee_id')) {
                $table->unsignedBigInteger('employee_id')->nullable()->after('customer_id');
            }
            if (! Schema::hasColumn('orders', 'supplier_id')) {
                $table->unsignedBigInteger('supplier_id')->nullable()->after('employee_id');
            }
            if (! Schema::hasColumn('orders', 'engine_discount_amount')) {
                $table->decimal('engine_discount_amount', 15, 3)->default(0)->after('discount_amount');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            foreach (['engine_discount_amount', 'supplier_id', 'employee_id', 'customer_id'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
