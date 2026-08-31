<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes deleting a group reversible, matching the other CRM entities.
 *
 * Note what this does NOT change: customers.group_id still carries
 * ON DELETE SET NULL, but a soft delete is an UPDATE, so that rule never
 * fires. Detaching the members is therefore done in application code when a
 * group is soft-deleted — see CustomerGroupController::destroy().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_groups', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('customer_groups', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
