<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enforce GLOBAL uniqueness for payments.reference_number.
     *
     * BEFORE: unique(invoice_id, reference_number) — composite, allows same ref across invoices
     * AFTER:  unique(reference_number)              — globally unique
     *
     * Steps:
     * 1. Ensure invoice_id has its own index (for FK constraint)
     * 2. Drop the old composite unique index uq_payments_invoice_ref
     * 3. Clean up any duplicate reference_number values
     * 4. Add new globally unique index on reference_number alone
     */
    public function up(): void
    {
        // 1. Add a dedicated index on invoice_id first (FK needs an index on this column)
        //    This allows us to drop the composite index without breaking FK constraints
        Schema::table('payments', function (Blueprint $table) {
            $table->index('invoice_id', 'idx_payments_invoice_id');
        });

        // 2. Drop the old composite unique index
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('uq_payments_invoice_ref');
        });

        // 3. Handle potential duplicate reference_numbers before adding global unique
        $duplicates = DB::table('payments')
            ->select('reference_number')
            ->whereNotNull('reference_number')
            ->groupBy('reference_number')
            ->having(DB::raw('COUNT(*)'), '>', 1)
            ->get();

        foreach ($duplicates as $dup) {
            // Keep the FIRST payment (lowest ID) with this reference, nullify the rest
            $keepId = DB::table('payments')
                ->where('reference_number', $dup->reference_number)
                ->orderBy('id')
                ->value('id');

            DB::table('payments')
                ->where('reference_number', $dup->reference_number)
                ->where('id', '!=', $keepId)
                ->update(['reference_number' => null]);
        }

        // 4. Add globally unique index on reference_number alone
        Schema::table('payments', function (Blueprint $table) {
            $table->unique('reference_number', 'uq_payments_reference_number_global');
        });
    }

    /**
     * Reverse: restore the old composite unique index
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('uq_payments_reference_number_global');
            $table->dropIndex('idx_payments_invoice_id');
            $table->unique(['invoice_id', 'reference_number'], 'uq_payments_invoice_ref');
        });
    }
};
