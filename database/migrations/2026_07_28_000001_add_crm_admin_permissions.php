<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private array $permissions = [
        'crm.access',
        'crm.dashboard.view',
        'crm.customer-orders.view',
        'crm.customer-addresses.view',
        'crm.complaints.view',
        'crm.notes.view',
        'crm.occasions.view',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $operational = [
            'crm.access',
            'crm.dashboard.view',
            'crm.view-customers',
            'crm.customer-orders.view',
            'crm.customer-addresses.view',
            'crm.complaints.view',
            'crm.notes.view',
            'crm.occasions.view',
        ];

        Role::whereIn('name', ['super-admin', 'branch-manager', 'call-center'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($operational));

        Role::where('name', 'accountant')->first()?->givePermissionTo(array_merge($operational, [
            'crm.view-customer-financial',
            'crm.view-customer-statement',
            'crm.manage-customer-credit',
        ]));

        Role::where('name', 'super-admin')->first()?->givePermissionTo(Permission::all());
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::whereIn('name', $this->permissions)->delete();
    }
};
