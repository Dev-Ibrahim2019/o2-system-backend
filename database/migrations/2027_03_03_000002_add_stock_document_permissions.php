<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * صلاحيات المرحلة 3. إنشاء المسودة منفصل عن ترحيلها، حتى يُدخل أمين المخزن ويرحّل المسؤول.
 * تُمنح لـ super-admin فقط، وبقية الأدوار من شاشة الأدوار والصلاحيات.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'inventory.documents.manage',   // إنشاء وتعديل وحذف مسودات الاستلام والتحويل والهالك
        'inventory.documents.post',     // ترحيل المستندات
        'inventory.documents.reverse',  // عكس المستندات المرحّلة
        'inventory.suppliers.manage',   // ربط الأصناف بالموردين وأسعارهم
        'inventory.costs.view',         // رؤية التكاليف والقيم في شاشات المخزون
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
