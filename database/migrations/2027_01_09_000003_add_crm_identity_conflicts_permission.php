<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permissions are managed by migration throughout this project (see the four
 * earlier `*_crm_*_permissions` migrations) — there is no permission seeder,
 * so this is the only way the grant survives a deploy.
 *
 * Viewing the conflict queue rides on the existing crm.view-customers, since a
 * ticket exposes nothing a customer record doesn't. Acting on one is what
 * needs its own permission: resolutions rename customers and create records.
 */
return new class extends Migration
{
    private const PERMISSION = 'crm.manage-identity-conflicts';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate(self::PERMISSION, 'web');

        // Verified against the live roles: crm.edit-customers is currently
        // held by super-admin, crm-manager and call-center.
        //
        // call-center is deliberately NOT granted this one. Call-center agents
        // are what *generate* these conflicts; letting them close their own
        // would make the review step ceremonial. Renaming a customer stays
        // reachable to them through crm.edit-customers as before — this
        // permission only governs the conflict queue.
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
