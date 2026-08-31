<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The name that was typed but lost.
 *
 * When an order matches an existing customer, `customer_name` records the
 * stored official name — correct, and deliberately so. But that means an order
 * placed under a different name is indistinguishable in the table from one
 * placed under the right one, which is exactly why the identity-conflict queue
 * could not offer a reviewer the orders that probably belong to the split-off
 * customer.
 *
 * Written only when the two names actually differ (same check that raises the
 * ticket). NULL is the normal case: no conflict, or a brand-new customer.
 *
 * Not backfilled — orders placed before this column existed keep NULL, and are
 * simply never candidates. That is accurate rather than guessed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('incoming_customer_name')->nullable()->after('customer_name');

            // candidateOrders() filters on (customer_id, incoming_customer_name).
            $table->index(['customer_id', 'incoming_customer_name'], 'orders_incoming_name_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_incoming_name_index');
            $table->dropColumn('incoming_customer_name');
        });
    }
};
