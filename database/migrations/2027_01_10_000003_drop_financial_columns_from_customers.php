<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the eight financial columns from `customers`.
 *
 * This is the step that makes the separation real: after it, no query against
 * `customers` — including a future one written by someone who never read any
 * of this — can return receivables data, because the data is not there.
 *
 * Re-verifies the backfill before dropping. The preceding migration already
 * checked, but this one destroys the source, so it checks again rather than
 * trusting that it ran.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'tax_number', 'currency', 'risk_level', 'credit_limit',
        'payment_terms', 'credit_days', 'opening_balance', 'is_opening_balance_posted',
    ];

    public function up(): void
    {
        $customers = DB::table('customers')->count();
        $profiles = DB::table('customer_financial_profiles')->count();

        if ($profiles < $customers) {
            throw new RuntimeException(
                "لا يمكن حذف الأعمدة: {$profiles} ملفاً ماليًا مقابل {$customers} عميلاً. شغّل ترحيل النسخ أولًا."
            );
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('tax_number', 100)->nullable()->after('code');
            $table->string('currency', 10)->nullable()->default('ILS');
            $table->string('risk_level', 20)->nullable()->default('low');
            $table->decimal('credit_limit', 15, 3)->default(0);
            $table->string('payment_terms', 50)->nullable()->default('net30');
            $table->integer('credit_days')->nullable()->default(30);
            $table->decimal('opening_balance', 15, 3)->default(0);
            $table->boolean('is_opening_balance_posted')->default(false);
        });

        // Put the values back where they came from, so a rollback restores the
        // previous behaviour rather than leaving empty columns behind.
        DB::statement('
            UPDATE customers c
            JOIN customer_financial_profiles p ON p.customer_id = c.id
            SET c.tax_number = p.tax_number,
                c.currency = p.currency,
                c.risk_level = p.risk_level,
                c.credit_limit = p.credit_limit,
                c.payment_terms = p.payment_terms,
                c.credit_days = p.credit_days,
                c.opening_balance = p.opening_balance,
                c.is_opening_balance_posted = p.is_opening_balance_posted
        ');
    }
};
