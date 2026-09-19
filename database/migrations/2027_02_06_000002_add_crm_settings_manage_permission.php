<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * `crm.settings.manage` — super-admin only, deliberately not crm-manager.
 *
 * This gates the module kill-switch (crm_settings.enabled), which can lock
 * every CRM user out at once — a different order of blast radius than the
 * per-employee delegation crm.staff.manage-permissions already allows
 * crm-manager. Keeping it at the top of the hierarchy means a crm-manager
 * can never accidentally (or by a compromised account) take the whole
 * module down for everyone.
 */
return new class extends Migration
{
    private const PERMISSION = 'crm.settings.manage';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate(self::PERMISSION, 'web');

        Role::where('name', 'super-admin')->first()?->givePermissionTo(self::PERMISSION);
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::where('name', self::PERMISSION)->delete();
    }
};
