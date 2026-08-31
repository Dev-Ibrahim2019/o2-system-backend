<?php

use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 3 of 3 — make the polymorphic columns required and remove customer_id.
 *
 * The old column is dropped rather than left beside the new ones, for the same
 * reason the financial columns were physically moved off `customers`: two
 * sources for one fact drift, and the dead one gets read by accident. After
 * this migration the owner is expressed in exactly one place.
 *
 * The cost is the real one to acknowledge: customer_id carried a foreign key
 * with cascade-on-delete, and a polymorphic column cannot. Deleting a customer
 * no longer removes their occasions at the database level — Customer's
 * morphMany does not cascade either. That is inherent to polymorphic
 * ownership, not something this migration chooses; it is called out here so
 * the next person does not assume the old guarantee still holds.
 */
return new class extends Migration
{
    public function up(): void
    {
        $unfilled = DB::table('customer_occasions')
            ->whereNull('occasionable_type')
            ->orWhereNull('occasionable_id')
            ->count();

        if ($unfilled > 0) {
            throw new RuntimeException(
                "Refusing to drop customer_id: {$unfilled} occasion(s) still have no owner. "
                .'Run the backfill migration first.'
            );
        }

        Schema::table('customer_occasions', function (Blueprint $table) {
            // Drop the FK before the column; MySQL keeps the index otherwise.
            $table->dropForeign(['customer_id']);
            $table->dropColumn('customer_id');
        });

        DB::statement('ALTER TABLE customer_occasions MODIFY occasionable_type VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE customer_occasions MODIFY occasionable_id BIGINT UNSIGNED NOT NULL');
    }

    public function down(): void
    {
        Schema::table('customer_occasions', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        // Only customer-owned occasions can be expressed by the old column;
        // any group-owned row created after the migration has no home in it.
        DB::table('customer_occasions')
            ->where('occasionable_type', Customer::class)
            ->update(['customer_id' => DB::raw('occasionable_id')]);

        DB::statement('ALTER TABLE customer_occasions MODIFY occasionable_type VARCHAR(255) NULL');
        DB::statement('ALTER TABLE customer_occasions MODIFY occasionable_id BIGINT UNSIGNED NULL');
    }
};
