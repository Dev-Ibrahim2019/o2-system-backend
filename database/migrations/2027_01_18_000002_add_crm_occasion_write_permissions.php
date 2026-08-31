<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Lets CRM create, update and delete occasions under its own permissions.
 *
 * `crm.occasions.view` was the only occasion permission that existed, guarding
 * one read route. Every write lived on the Call Center routes behind that
 * module's role group — so a crm-manager could see a customer's occasions and
 * change nothing about them, and got a 403 on the only create path.
 *
 * A delete permission is included here, unlike complaints: an occasion is a
 * calendar entry, not a record of something that happened, and a wrong one
 * (a mistyped date, a duplicate) has no history worth preserving. The model
 * soft-deletes, so the row survives even so.
 */
return new class extends Migration
{
    private array $permissions = [
        'crm.occasions.create',
        'crm.occasions.update',
        'crm.occasions.delete',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Exactly the roles that already hold crm.occasions.view (verified
        // before writing this): super-admin, crm-manager, call-center. This
        // opens no occasion data to anyone who could not already read it.
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
