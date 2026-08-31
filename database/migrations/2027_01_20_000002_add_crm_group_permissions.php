<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Gives customer groups their own permissions.
 *
 * Until now the one group route rode on crm.view-customers — a permission
 * about customers, borrowed because nothing better existed. Reading groups
 * keeps the same three roles that already hold it, so nobody gains access.
 *
 * The writes are narrower on purpose: a group is shared structure, and a
 * call-centre agent renaming or deleting one would silently change what every
 * other agent sees. Managing them belongs with crm-manager.
 */
return new class extends Migration
{
    private const VIEW = 'crm.groups.view';

    private array $writes = [
        'crm.groups.create',
        'crm.groups.update',
        'crm.groups.delete',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([self::VIEW, ...$this->writes] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Verified before writing: exactly these three hold crm.view-customers.
        Role::whereIn('name', ['super-admin', 'crm-manager', 'call-center'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo(self::VIEW));

        Role::whereIn('name', ['super-admin', 'crm-manager'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($this->writes));
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::whereIn('name', [self::VIEW, ...$this->writes])->delete();
    }
};
