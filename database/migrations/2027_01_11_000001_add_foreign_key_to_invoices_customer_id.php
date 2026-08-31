<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `invoices.customer_id` has always been a bare bigint — no foreign key.
 *
 * It is the fullest financial table after `orders`, so nothing was stopping a
 * row from pointing at a customer id that does not exist. Every other table in
 * the customer scope (twelve of them) already carries the constraint; this one
 * was simply missed.
 *
 * Verified clean before writing this: 23 invoices, 4 carrying a customer_id,
 * 0 orphans.
 *
 * restrictOnDelete, not cascade: an invoice is an accounting record. Deleting a
 * customer must never silently delete their invoices — it should fail loudly
 * instead. In practice Customer uses SoftDeletes, so ordinary deletion is an
 * UPDATE and never reaches this constraint; only a forceDelete would, which is
 * exactly the case worth blocking.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Re-check rather than trust the earlier manual query — this migration
        // may run much later, on another database.
        $orphans = DB::table('invoices')
            ->whereNotNull('customer_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('customers')
                ->whereColumn('customers.id', 'invoices.customer_id'))
            ->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "لا يمكن فرض القيد: {$orphans} فاتورة تشير إلى عميل غير موجود. عالجها أولًا."
            );
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('customer_id', 'invoices_customer_id_foreign')
                ->references('id')->on('customers')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign('invoices_customer_id_foreign');
        });
    }
};
