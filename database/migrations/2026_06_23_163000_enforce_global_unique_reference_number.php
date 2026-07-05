<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        // 1. Add a dedicated index on invoice_id first (FK needs an index on this column)
        //    This allows us to drop the composite index without breaking FK constraints
        Schema::table('payments', function (Blueprint $table) {
            $table->index('invoice_id', 'idx_payments_invoice_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('uq_payments_invoice_ref');
        });

        $duplicates = DB::table('payments')
            ->select('reference_number')
            ->whereNotNull('reference_number')
            ->groupBy('reference_number')
            ->having(DB::raw('COUNT(*)'), '>', 1)
            ->get();

        foreach ($duplicates as $dup) {
            $keepId = DB::table('payments')
                ->where('reference_number', $dup->reference_number)
                ->orderBy('id')
                ->value('id');

            DB::table('payments')
                ->where('reference_number', $dup->reference_number)
                ->where('id', '!=', $keepId)
                ->update(['reference_number' => null]);
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->unique('reference_number', 'uq_payments_reference_number_global');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payments')) return;

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('uq_payments_reference_number_global');
            $table->dropIndex('idx_payments_invoice_id');
            $table->unique(['invoice_id', 'reference_number'], 'uq_payments_invoice_ref');
        });
    }
};
