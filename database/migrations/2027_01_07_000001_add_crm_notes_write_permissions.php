<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    // نفس الأدوار التي تملك crm.notes.view حالياً (super-admin, branch-manager,
    // call-center مباشرة، وaccountant عبر دمج $operational في migration
    // 2026_07_28_000001) — الكتابة تُمنح لمن يملك القراءة أصلاً، بنفس مبدأ
    // بقية صلاحيات CRM في هذا المشروع.
    private array $permissions = [
        'crm.notes.create',
        'crm.notes.update',
        'crm.notes.delete',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::whereIn('name', ['super-admin', 'branch-manager', 'call-center', 'accountant'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($this->permissions));
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::whereIn('name', $this->permissions)->delete();
    }
};
