<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * crm.customer-orders.manage — narrower than the existing .view permission
 * every operational CRM role already holds (super-admin, branch-manager,
 * call-center, accountant, crm-manager — see
 * 2026_07_28_000001_add_crm_admin_permissions.php). This one only gates
 * changing the company-wide order-delay alert threshold
 * (Crm\OrderDelaySettingController::update()), not reading orders.
 *
 * Mirrors the crm.loyalty.view/.manage split
 * (2027_01_23_000001_add_crm_loyalty_permissions.php): crm-manager owns the
 * CRM module's settings, super-admin has everything. branch-manager/
 * call-center/accountant keep read access to orders but do not get to
 * change a company-wide alerting rule from their own branch's screen.
 */
return new class extends Migration
{
    private const MANAGE = 'crm.customer-orders.manage';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate(self::MANAGE, 'web');

        Role::whereIn('name', ['crm-manager', 'super-admin'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo(self::MANAGE));
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::where('name', self::MANAGE)->delete();
    }
};
