<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Widens crm.customer-orders.view-cross-branch (added in
 * 2027_02_09_000001) from crm-manager/super-admin only to every role that
 * already holds crm.customer-orders.view.
 *
 * Reason: the customer is global by design, so a customer's FINISHED order
 * history is part of their profile, not privileged information — an agent
 * at Gaza looking at a customer who last ordered from Nuseirat must see
 * that order, otherwise the profile silently shows an incomplete history
 * and the agent cannot serve the caller. Restricting this to managers made
 * every ordinary CRM/branch agent see a partial profile, which is the exact
 * silent-gap this whole change set set out to remove.
 *
 * Unchanged by this migration:
 *  - Another branch's ACTIVE orders are still never returned, for anyone.
 *  - Read only: no write path opens up, the operational order endpoints
 *    stay BranchScope-protected exactly as before.
 *  - crm.complaints.assign-cross-branch stays manager-only — handing a
 *    complaint to another branch's staff is a real operational decision,
 *    unlike reading a finished order.
 */
return new class extends Migration
{
    private const VIEW_CROSS_BRANCH = 'crm.customer-orders.view-cross-branch';
    private const BASE_VIEW = 'crm.customer-orders.view';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate(self::VIEW_CROSS_BRANCH, 'web');

        // Derived from who actually holds the base permission today rather
        // than a hardcoded role list, so this cannot drift from it.
        Role::whereHas('permissions', fn ($q) => $q->where('name', self::BASE_VIEW))
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo(self::VIEW_CROSS_BRANCH));
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Back to the original narrow grant, not a full delete — the
        // permission itself belongs to the previous migration.
        Role::whereHas('permissions', fn ($q) => $q->where('name', self::VIEW_CROSS_BRANCH))
            ->get()
            ->reject(fn (Role $role) => in_array($role->name, ['crm-manager', 'super-admin'], true))
            ->each(fn (Role $role) => $role->revokePermissionTo(self::VIEW_CROSS_BRANCH));
    }
};
