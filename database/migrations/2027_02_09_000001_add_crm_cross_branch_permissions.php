<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Two narrow permissions for explicit cross-branch CRM access, added as part
 * of the branch-ownership fix for complaints/orders:
 *
 * - crm.customer-orders.view-cross-branch: read a customer's COMPLETED (or
 *   cancelled) orders from a branch other than the viewer's own, inside the
 *   customer 360 profile only — never active orders from another branch, and
 *   never a write action on one. Narrower than the existing
 *   crm.customer-orders.view every operational CRM role already holds.
 *
 * - crm.complaints.assign-cross-branch: hand a complaint to (or take
 *   ownership from) a user/employee in a different branch than the
 *   complaint's own branch_id. Narrower than crm.complaints.assign, which
 *   still gates same-branch reassignment on its own.
 *
 * Granted only to crm-manager and super-admin, mirroring the precedent set
 * by crm.customer-orders.manage (2027_02_01_000003) — branch-manager/
 * call-center/accountant keep ordinary same-branch access but do not get
 * cross-branch reach by default.
 */
return new class extends Migration
{
    private const VIEW_CROSS_BRANCH = 'crm.customer-orders.view-cross-branch';
    private const ASSIGN_CROSS_BRANCH = 'crm.complaints.assign-cross-branch';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate(self::VIEW_CROSS_BRANCH, 'web');
        Permission::findOrCreate(self::ASSIGN_CROSS_BRANCH, 'web');

        Role::whereIn('name', ['crm-manager', 'super-admin'])
            ->get()
            ->each(function (Role $role) {
                $role->givePermissionTo(self::VIEW_CROSS_BRANCH);
                $role->givePermissionTo(self::ASSIGN_CROSS_BRANCH);
            });
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::whereIn('name', [self::VIEW_CROSS_BRANCH, self::ASSIGN_CROSS_BRANCH])->delete();
    }
};
