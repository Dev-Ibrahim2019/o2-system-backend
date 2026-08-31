<?php

use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Step 2 of 3 — point every existing row at its customer.
 *
 * Counts before and after and refuses to finish on a mismatch. Every occasion
 * on this table today belongs to a customer by definition (customer_id is NOT
 * NULL and foreign-keyed), so a row left unfilled would mean the update itself
 * failed — not a data condition to tolerate.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Soft-deleted rows are migrated too: they are still owned, and
        // leaving them null would break the NOT NULL in step 3.
        $before = DB::table('customer_occasions')->count();

        DB::table('customer_occasions')->update([
            'occasionable_type' => Customer::class,
            'occasionable_id' => DB::raw('customer_id'),
        ]);

        $after = DB::table('customer_occasions')->count();
        $filled = DB::table('customer_occasions')
            ->whereNotNull('occasionable_type')
            ->whereNotNull('occasionable_id')
            ->count();

        $mismatched = DB::table('customer_occasions')
            ->whereRaw('occasionable_id <> customer_id')
            ->count();

        if ($after !== $before || $filled !== $before || $mismatched !== 0) {
            throw new RuntimeException(
                "Occasion backfill failed integrity check: before={$before}, after={$after}, "
                ."filled={$filled}, mismatched={$mismatched}."
            );
        }
    }

    public function down(): void
    {
        DB::table('customer_occasions')->update([
            'occasionable_type' => null,
            'occasionable_id' => null,
        ]);
    }
};
