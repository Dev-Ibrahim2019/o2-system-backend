<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private array $permissions = [
        'crm.view-customers',
        'crm.create-customers',
        'crm.edit-customers',
        'crm.delete-customers',
        'crm.view-customer-financial',
        'crm.view-customer-statement',
        'crm.export-customer-statement',
        'crm.manage-customer-credit',
        'crm.view-sensitive-notes',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::where('name', 'super-admin')->first()?->givePermissionTo($this->permissions);
        Role::where('name', 'accountant')->first()?->givePermissionTo($this->permissions);

        $customerOperations = [
            'crm.view-customers',
            'crm.create-customers',
            'crm.edit-customers',
        ];
        Role::whereIn('name', ['branch-manager', 'cashier', 'call-center'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($customerOperations));
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::whereIn('name', $this->permissions)->delete();
    }
};
