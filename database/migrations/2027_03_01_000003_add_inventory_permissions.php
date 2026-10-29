<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * صلاحيات وحدة المخازن داخل نظام الصلاحيات الحالي (spatie).
 * تُمنح لـ super-admin فقط؛ بقية الأدوار تُضبط من شاشة الأدوار والصلاحيات.
 *
 * الوصول لمخزن معيّن لا يأتي من هذه الصلاحيات وحدها: يجب أيضاً أن يكون
 * المستخدم مربوطاً بالمخزن (warehouse_user)، أو يملك inventory.warehouses.all.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'inventory.access',            // الدخول للوحدة وعرض الأصناف والتصنيفات والوحدات
        'inventory.items.manage',      // إنشاء وتعديل وإيقاف الأصناف المخزنية
        'inventory.catalog.manage',    // إدارة التصنيفات ووحدات القياس
        'inventory.warehouses.manage', // إنشاء وتعديل المخازن وربط المستخدمين بها
        'inventory.warehouses.all',    // الوصول لكل المخازن دون ربط صريح
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
