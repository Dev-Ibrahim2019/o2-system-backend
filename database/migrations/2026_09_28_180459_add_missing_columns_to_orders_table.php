<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Safe migration: guards against missing table since orders table
     * was created by a later-dated migration (2026_09_28_000001).
     */
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        $columns = Schema::getColumnListing('orders');

        Schema::table('orders', function (Blueprint $table) use ($columns): void {
            if (!in_array('customer_id', $columns, true)) {
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            }
            if (!in_array('employee_id', $columns, true)) {
                $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            }
            if (!in_array('supplier_id', $columns, true)) {
                $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            }
            if (!in_array('engine_discount_amount', $columns, true)) {
                $table->decimal('engine_discount_amount', 10, 3)->default(0);
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        $columns = Schema::getColumnListing('orders');

        Schema::table('orders', function (Blueprint $table) use ($columns): void {
            if (in_array('customer_id', $columns, true)) {
                $table->dropForeign(['customer_id']);
                $table->dropColumn('customer_id');
            }
            if (in_array('employee_id', $columns, true)) {
                $table->dropForeign(['employee_id']);
                $table->dropColumn('employee_id');
            }
            if (in_array('supplier_id', $columns, true)) {
                $table->dropForeign(['supplier_id']);
                $table->dropColumn('supplier_id');
            }
            if (in_array('engine_discount_amount', $columns, true)) {
                $table->dropColumn('engine_discount_amount');
            }
        });
    }
};