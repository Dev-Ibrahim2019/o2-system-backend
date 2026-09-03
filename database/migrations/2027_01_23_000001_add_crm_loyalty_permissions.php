<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Two permissions for the loyalty API surface — no new role.
 *
 * Confirmed by the loyalty-foundation audit before this was written: no user
 * today combines a finance permission with crm-manager, and the project's own
 * pattern (this migration included) is to grant one permission to several
 * existing roles rather than invent a composite role nobody holds yet.
 *
 * crm.loyalty.view mirrors exactly who already holds crm.view-customers
 * (call-center, crm-manager, super-admin) — reading balances is reading
 * customer data, nothing more.
 *
 * crm.loyalty.manage is deliberately narrower: crm-manager (owns the CRM
 * module), accountant (points are a financial liability), and super-admin.
 * call-center reads balances but does not define rules or post manual
 * adjustments — the same separation crm.groups.* already draws between
 * crm.view-customers (read, three roles) and crm.groups.create/update/delete
 * (write, crm-manager only).
 */
return new class extends Migration
{
    private const VIEW = 'crm.loyalty.view';
    private const MANAGE = 'crm.loyalty.manage';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([self::VIEW, self::MANAGE] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::whereIn('name', ['call-center', 'crm-manager', 'super-admin'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo(self::VIEW));

        Role::whereIn('name', ['crm-manager', 'accountant', 'super-admin'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo(self::MANAGE));
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::whereIn('name', [self::VIEW, self::MANAGE])->delete();
    }
};
