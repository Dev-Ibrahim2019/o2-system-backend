<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the domain-classification field that distinguishes:
     * - 'operational' customers (created from CRM / Call Center / future
     *   Cashier / Website flows) — visible in CRM only.
     * - 'financial' customers (created from Accounting's own "Add Customer"
     *   workflow) — visible in both CRM and Accounting.
     *
     * Does NOT touch currency/risk_level/payment_terms/credit_days/
     * credit_limit/opening_balance/is_opening_balance_posted or their
     * existing database defaults.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Default 'operational' is a safety net for any future creation
            // path that forgets to pass the type explicitly: worst case is
            // a customer stays hidden from Accounting, never the reverse.
            $table->string('customer_type', 20)->default('operational')->after('status');
        });

        // Every customer that already exists today is already visible in
        // Accounting's Customer Accounts screen (there was no distinction
        // before this migration). Preserve that behavior exactly: classify
        // all pre-existing rows as 'financial' rather than guessing origin.
        DB::table('customers')->update(['customer_type' => 'financial']);
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('customer_type');
        });
    }
};
