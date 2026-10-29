<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * لوحة المخازن المستقلة: دور storekeeper (أمين مخزن) وتفصيل inventory.items.manage
 * إلى إنشاء/تعديل/حذف. كل دور يملك items.manage يأخذ الثلاث حتى لا يفقد أحد وصوله.
 * أمين المخزن محصور بـ /api/inventory/* عبر RestrictScopedRoles.
 */
return new class extends Migration
{
    private const ITEM_PERMISSIONS = [
        'inventory.items.create',
        'inventory.items.edit',
        'inventory.items.delete',
    ];

    /** صلاحيات أمين المخزن: كميات فقط — بلا تكاليف ولا إعدادات ولا حذف ولا عكس، ومخازنه المربوط بها فقط. */
    private const STOREKEEPER = [
        'inventory.access',
        'inventory.items.create',
        'inventory.items.edit',
        'inventory.documents.manage',
        'inventory.documents.post',
        'inventory.counts.manage',
        'inventory.counts.post',
        'inventory.analytics.view',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::ITEM_PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $legacy = Permission::where('name', 'inventory.items.manage')->where('guard_name', 'web')->first();
        if ($legacy) {
            foreach ($legacy->roles as $role) {
                $role->givePermissionTo(self::ITEM_PERMISSIONS);
            }
            foreach ($legacy->users as $user) {
                $user->givePermissionTo(self::ITEM_PERMISSIONS);
            }
        }
        Role::where('name', 'super-admin')->first()?->givePermissionTo(self::ITEM_PERMISSIONS);

        $existing = Permission::whereIn('name', self::STOREKEEPER)->where('guard_name', 'web')->pluck('name')->all();
        Role::findOrCreate('storekeeper', 'web')->givePermissionTo($existing);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::where('name', 'storekeeper')->where('guard_name', 'web')->delete();
        Permission::whereIn('name', self::ITEM_PERMISSIONS)->delete();
    }
};
