<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which door a complaint came in through.
 *
 * An enum rather than the varchar this table uses for type/priority/status:
 * those grew organically and carry values the database cannot police, whereas
 * a channel is a closed set owned by the code — there is no such thing as a
 * complaint from a channel we have not built. It follows
 * customer_identity_conflicts.source_channel, the newer of the two patterns
 * the codebase offers. Safe to constrain now because the table is empty (0
 * rows verified before writing this), so no existing value can be rejected.
 *
 * 'website' is listed with no endpoint behind it yet — adding a value to a
 * MySQL enum later is a table rebuild, so the cheap moment to reserve it is
 * now, while the table has nothing in it.
 *
 * Nullable, with no default, on purpose: the value must be supplied by the
 * entry point (see CallCenterService::createComplaint's required $channel
 * argument). A default would let a future caller forget and still look
 * correct, which is exactly the failure this column exists to prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_complaints', function (Blueprint $table) {
            $table->enum('channel', ['call_center', 'crm', 'website'])
                ->nullable()
                ->after('branch_id');

            // Complaints get filtered by channel far more often than they get
            // looked up by it alone, so this pairs with the created_at sort
            // the listing already uses.
            $table->index(['channel', 'created_at'], 'cc_channel_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('customer_complaints', function (Blueprint $table) {
            $table->dropIndex('cc_channel_created_index');
            $table->dropColumn('channel');
        });
    }
};
