<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moves receivables data off the customer row.
 *
 * Until now the eight financial columns sat on `customers` beside name, phone
 * and city. Customer has no $hidden, so any endpoint returning a whole model
 * shipped credit_limit, payment_terms and opening_balance to whoever asked —
 * proven against CrmController::store(), which returned all 35 keys with no
 * financial permission involved.
 *
 * Per-endpoint filtering was the alternative, and it is what the codebase
 * already tried: scattered abort_unless() calls that every future endpoint has
 * to remember. Physical separation removes the need to remember — a query
 * against `customers` cannot leak a column that is not in the table.
 *
 * One row per customer at most; a customer with no receivables activity simply
 * has no row here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_financial_profiles', function (Blueprint $table) {
            $table->id();

            // Unique => strictly 1-1. Cascade: a deleted customer has no
            // receivables profile to keep.
            $table->foreignId('customer_id')->unique()->constrained('customers')->cascadeOnDelete();

            // Same types the columns had on `customers`, verified against the
            // live schema rather than re-guessed.
            $table->string('tax_number', 100)->nullable();
            $table->string('currency', 10)->nullable()->default('ILS');
            $table->string('risk_level', 20)->nullable()->default('low');
            $table->decimal('credit_limit', 15, 3)->default(0);
            $table->string('payment_terms', 50)->nullable()->default('net30');
            $table->integer('credit_days')->nullable()->default(30);
            $table->decimal('opening_balance', 15, 3)->default(0);
            $table->boolean('is_opening_balance_posted')->default(false);

            $table->timestamps();

            // Accounting's customer list filters and sorts on risk_level, and
            // searches tax_number — both become joins once the columns move,
            // so they need indexes to stay as fast as they were.
            $table->index('risk_level', 'cfp_risk_level_index');
            $table->index('tax_number', 'cfp_tax_number_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_financial_profiles');
    }
};
