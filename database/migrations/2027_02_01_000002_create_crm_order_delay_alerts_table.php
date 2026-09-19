<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (order, threshold) pair the delay-alert job has already
 * notified CRM staff about — see CrmOrderDelayAlertService::checkAndNotify().
 * The unique pair is what makes re-running the job every minute idempotent:
 * an order already alerted at the currently configured threshold is skipped,
 * but if a manager later raises the threshold and that same still-active
 * order crosses the new, larger number too, that is a distinct pair and
 * fires once more. Lowering the threshold back down never re-fires an
 * already recorded pair.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_order_delay_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('threshold_minutes');
            $table->timestamp('notified_at');
            $table->unique(['order_id', 'threshold_minutes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_order_delay_alerts');
    }
};
