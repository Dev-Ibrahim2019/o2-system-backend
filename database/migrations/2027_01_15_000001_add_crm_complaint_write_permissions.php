<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Lets CRM create and update complaints under its own permissions.
 *
 * Until now `crm.complaints.view` was the only complaint permission that
 * existed, and it guarded a single read route. Every write lived on the Call
 * Center routes, gated by role — so a crm-manager could see complaints and do
 * nothing about them.
 *
 * No delete permission: a complaint is closed or cancelled, never erased. The
 * followup trail is the record of what happened to it.
 */
return new class extends Migration
{
    private array $permissions = [
        'crm.complaints.create',
        'crm.complaints.update',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // The same roles that already hold crm.complaints.view — this opens no
        // complaint data to anyone who could not already read it.
        Role::whereIn('name', ['super-admin', 'crm-manager', 'call-center'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($this->permissions));
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::whereIn('name', $this->permissions)->delete();
    }
};
