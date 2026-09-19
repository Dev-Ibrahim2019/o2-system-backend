<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * `crm.staff.manage-permissions` — the delegation permission itself.
 *
 * Granted to super-admin and crm-manager, but CrmStaffPermissionController
 * refuses to let anyone (including a super-admin acting through that
 * endpoint) grant, revoke or deny this specific permission — it is only ever
 * assigned through role membership (this migration, or the existing
 * RoleController), never through the new per-user override layer. That is
 * what stops one crm-manager from handing a subordinate the same delegation
 * power, or being locked out of it by a peer.
 */
return new class extends Migration
{
    private const PERMISSION = 'crm.staff.manage-permissions';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate(self::PERMISSION, 'web');

        Role::whereIn('name', ['super-admin', 'crm-manager'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo(self::PERMISSION));
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::where('name', self::PERMISSION)->delete();
    }
};
