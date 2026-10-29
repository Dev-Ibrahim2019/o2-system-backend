<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** صلاحيات المرحلة 4 (الوصفات والجرد) وصفحة التحليلات. تُمنح لـ super-admin فقط. */
return new class extends Migration
{
    private const PERMISSIONS = [
        'inventory.recipes.manage',   // إنشاء وتعديل الوصفات
        'inventory.counts.manage',    // إنشاء أوراق الجرد وإدخال المعدود
        'inventory.counts.post',      // ترحيل الجرد (يسجّل تسويات)
        'inventory.analytics.view',   // صفحة تحليلات المخزون
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
