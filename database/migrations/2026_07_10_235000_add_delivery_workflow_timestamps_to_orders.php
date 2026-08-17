<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (! Schema::hasColumn('orders', 'paid_at')) {
                    $table->dateTime('paid_at')->nullable();
                }
                if (! Schema::hasColumn('orders', 'assembled_at')) {
                    $table->dateTime('assembled_at')->nullable();
                }
                if (! Schema::hasColumn('orders', 'delivery_started_at')) {
                    $table->dateTime('delivery_started_at')->nullable();
                }
                if (! Schema::hasColumn('orders', 'delivered_at')) {
                    $table->dateTime('delivered_at')->nullable();
                }
                if (! Schema::hasColumn('orders', 'delivery_employee_name')) {
                    $table->string('delivery_employee_name')->nullable();
                }
                if (! Schema::hasColumn('orders', 'delivery_duration_seconds')) {
                    $table->unsignedInteger('delivery_duration_seconds')->nullable();
                }
            });
        }

        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                if (! Schema::hasColumn('order_items', 'item_prepared_at')) {
                    $table->dateTime('item_prepared_at')->nullable();
                }
                if (! Schema::hasColumn('order_items', 'prepared_duration_seconds')) {
                    $table->unsignedInteger('prepared_duration_seconds')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                foreach (['prepared_duration_seconds', 'item_prepared_at'] as $column) {
                    if (Schema::hasColumn('order_items', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                foreach ([
                    'delivery_duration_seconds',
                    'delivery_employee_name',
                    'delivered_at',
                    'delivery_started_at',
                    'assembled_at',
                    'paid_at',
                ] as $column) {
                    if (Schema::hasColumn('orders', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
