<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two admin-set targets, nullable by default (no invented number) — the
 * Reports page (CrmReportController) uses these to decide whether revenue
 * is "on target" and whether the cancellation rate has crossed an alert
 * threshold. Living on crm_settings rather than a new table: same
 * single-row scope as the module toggles already there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_settings', function (Blueprint $table) {
            $table->decimal('monthly_revenue_target', 12, 2)->nullable()->after('auto_register_pos_customers');
            $table->decimal('max_cancellation_rate_pct', 5, 2)->nullable()->after('monthly_revenue_target');
        });
    }

    public function down(): void
    {
        Schema::table('crm_settings', function (Blueprint $table) {
            $table->dropColumn(['monthly_revenue_target', 'max_cancellation_rate_pct']);
        });
    }
};
