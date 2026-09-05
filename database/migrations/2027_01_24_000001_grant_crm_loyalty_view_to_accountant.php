<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fixes a gap the loyalty documentation pass caught: accountant was granted
 * crm.loyalty.manage (2027_01_23_000001) but never crm.loyalty.view, and
 * every read in the loyalty module — including the ones a write screen loads
 * before showing its own write controls — requires crm.loyalty.view. The
 * practical effect was that an accountant with "full manage" permission saw
 * a 403 on every loyalty screen and could reach none of the write actions
 * they were supposed to have.
 *
 * crm.loyalty.view already mirrors crm.view-customers' role set
 * (call-center, crm-manager, super-admin) per the original design; this adds
 * accountant to that view list specifically, rather than widening
 * crm.view-customers itself or inventing a new permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::where('name', 'accountant')->first();
        if ($role) {
            $role->givePermissionTo('crm.loyalty.view');
        }
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::where('name', 'accountant')->first();
        if ($role) {
            $role->revokePermissionTo('crm.loyalty.view');
        }
    }
};
