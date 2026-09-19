<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A single, global row — same singleton shape as crm_order_delay_settings —
 * holding the two CRM settings that have real, enforced backend behavior:
 *
 * - `enabled`: a true module kill-switch, checked by the EnsureCrmModuleEnabled
 *   middleware on every crm/* route. Whoever holds crm.settings.manage always
 *   gets through regardless (see the middleware's own comment) — otherwise
 *   switching this off would lock everyone, including whoever could switch it
 *   back on, out at once.
 * - `auto_register_pos_customers`: gates PosCustomerLinkService::createFromCounter()
 *   only — the cashier counter, where an unlinked walk-in sale is already a
 *   normal, supported outcome. Deliberately does NOT touch Call Center's own
 *   identity-creation (CallCenterOrderCreationService): a Call Center order
 *   requires a real customer record for its delivery address, so turning this
 *   off there would break order creation, not skip a nice-to-have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->boolean('auto_register_pos_customers')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('crm_settings')->insert([
            'enabled' => true,
            'auto_register_pos_customers' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_settings');
    }
};
