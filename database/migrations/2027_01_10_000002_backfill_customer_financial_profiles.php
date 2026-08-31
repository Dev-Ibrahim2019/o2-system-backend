<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Copies the eight financial columns into their own table.
 *
 * Separate from both the create and the drop on purpose: if the copy is wrong,
 * it can be re-run or reversed while the source columns are still there. The
 * drop migration that follows verifies this one's result before removing
 * anything.
 *
 * Soft-deleted customers are included — their receivables history has to
 * survive alongside them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $expected = DB::table('customers')->count();

        DB::statement('
            INSERT INTO customer_financial_profiles
                (customer_id, tax_number, currency, risk_level, credit_limit,
                 payment_terms, credit_days, opening_balance, is_opening_balance_posted,
                 created_at, updated_at)
            SELECT id, tax_number, currency, risk_level, credit_limit,
                   payment_terms, credit_days, opening_balance, is_opening_balance_posted,
                   NOW(), NOW()
            FROM customers
        ');

        $copied = DB::table('customer_financial_profiles')->count();

        if ($copied !== $expected) {
            throw new RuntimeException(
                "نسخ الملفات المالية غير مكتمل: {$copied} من {$expected}. أُلغي الترحيل."
            );
        }

        // Value-level check, not just a row count — a row count would pass even
        // if every column had copied as NULL.
        $mismatch = DB::table('customers as c')
            ->join('customer_financial_profiles as p', 'p.customer_id', '=', 'c.id')
            ->where(function ($q) {
                foreach (['tax_number', 'currency', 'risk_level', 'credit_limit',
                          'payment_terms', 'credit_days', 'opening_balance',
                          'is_opening_balance_posted'] as $col) {
                    $q->orWhereRaw("NOT (c.{$col} <=> p.{$col})"); // <=> is NULL-safe
                }
            })
            ->count();

        if ($mismatch > 0) {
            throw new RuntimeException("تعارض في {$mismatch} صف بين المصدر والوجهة. أُلغي الترحيل.");
        }
    }

    public function down(): void
    {
        DB::table('customer_financial_profiles')->delete();
    }
};
