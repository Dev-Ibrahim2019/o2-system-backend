<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A single, global row holding the CRM order-delay alert threshold — the
 * same number the "الطلبات المتأخرة" screen's ?minutes= filter has always
 * expressed ad hoc, now persisted so a scheduled job (crm:orders:check-delays
 * — see CrmOrderDelayAlertService) can evaluate it without a request in
 * flight. Global, not per-branch: nothing about "how late is too late" is
 * branch-specific today, and the ?minutes= filter this replaces as the
 * source of truth was already company-wide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_order_delay_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('threshold_minutes')->default(30);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Seed the single row this table ever holds — CrmOrderDelaySetting
        // also creates it lazily if it's ever missing, but there is no
        // reason to make every first read pay for that.
        DB::table('crm_order_delay_settings')->insert([
            'threshold_minutes' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_order_delay_settings');
    }
};
