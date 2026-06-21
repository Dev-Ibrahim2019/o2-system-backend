<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add entity fields to payments table for subledger support.
     *
     * This enables tracking which entity (customer/employee/supplier)
     * a payment is associated with, so the accounting entry can use
     * the correct control account instead of cash.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('entity_type')->nullable()->after('method')
                ->comment('Type of entity: customer, employee, supplier');
            $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type')
                ->comment('ID of the entity (customers.id, employees.id, suppliers.id)');
            $table->string('subledger_type')->nullable()->after('entity_id')
                ->comment('Subledger type for accounting: customer, employee, supplier');
            $table->unsignedBigInteger('subledger_id')->nullable()->after('subledger_type')
                ->comment('Subledger ID for accounting entries');
            $table->unsignedBigInteger('payment_method_id')->nullable()->after('method')
                ->comment('FK to payment_methods table');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'entity_type',
                'entity_id',
                'subledger_type',
                'subledger_id',
                'payment_method_id',
            ]);
        });
    }
};
