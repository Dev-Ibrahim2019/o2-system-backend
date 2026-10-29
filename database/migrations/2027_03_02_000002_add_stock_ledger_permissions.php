<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * صلاحيات المرحلة 2. عرض الأرصدة وسجل حركة الصنف يكفيه inventory.access مع الوصول للمخزن
 * (WarehouseAccess). تُمنح لـ super-admin فقط، وبقية الأدوار من شاشة الأدوار والصلاحيات.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'inventory.movements.opening', // إدخال أرصدة أول المدة
        'inventory.movements.reverse', // عكس حركة مرحّلة يدوياً
        'inventory.settings.manage',   // إعدادات المخزون (السماح بالرصيد السالب)
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Role::where('name', 'super-admin')->first()?->givePermissionTo(self::PERMISSIONS);
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::whereIn('name', self::PERMISSIONS)->delete();
    }
};
