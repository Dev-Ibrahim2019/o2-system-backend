<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `customers.category` becomes `customers.engagement_status`.
 *
 * The column now carries one vocabulary only — the Call Center's engagement
 * tag: regular, important, vip, new, inactive, follow_up, complaints.
 * Left as a plain string rather than an enum: the set is still settling, and a
 * DB enum would have to be migrated on every addition. The constraint lives in
 * the request validation (CallCenterController), as it did before.
 *
 * The business classification that used to share this column now lives on
 * customer_groups.group_type.
 *
 * ── DATA DECISION, called out explicitly ──
 * Two rows hold 'retail', a business classification applied to an individual:
 *   customer 1  (CUS-000001)  category = 'retail'
 *   customer 4  (CUS-000004)  category = 'retail'
 * They are cleared to NULL. Keeping them would put a business classification
 * inside a column documented as engagement-only, re-creating on day one the
 * exact vocabulary mixing this migration exists to end. Neither customer is a
 * company, so neither gets an auto-created group — as instructed.
 * down() restores both values.
 */
return new class extends Migration
{
    /** Values that belong to customer_groups.group_type, not to an individual. */
    private const BUSINESS_VALUES = ['retail', 'wholesale', 'corporate', 'government', 'service'];

    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->renameColumn('category', 'engagement_status');
        });

        DB::table('customers')
            ->whereIn('engagement_status', self::BUSINESS_VALUES)
            ->update(['engagement_status' => null]);
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->renameColumn('engagement_status', 'category');
        });

        // Restore the two individuals whose business classification was cleared.
        DB::table('customers')->whereIn('id', [1, 4])->update(['category' => 'retail']);
    }
};
