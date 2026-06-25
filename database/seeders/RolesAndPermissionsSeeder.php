<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. إعادة تعيين الكاش (مهم جداً قبل أي تعديل)
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // 2. حذف البيانات القديمة (اختياري — للتطوير فقط)
        // Role::query()->delete();
        // Permission::query()->delete();

        // ══════════════════════════════════════════════════════
        //  إنشاء الصلاحيات (15 صلاحية)
        // ══════════════════════════════════════════════════════

        $permissions = [
            // ── الأفرع ──
            'manage-branches',

            // ── الأقسام ──
            'manage-departments',

            // ── الأصناف ──
            'manage-items',

            // ── الموظفين ──
            'manage-employees',

            // ── المحاسبة ──
            'view-accounting',
            'manage-accounting',

            // ── الطلبات ──
            'manage-orders',
            'view-orders',

            // ── التقارير ──
            'view-reports',

            // ── الإعدادات ──
            'manage-settings',

            // ── الأرشيف والتدقيق ──
            'view-audit-log',
            'view-archive',

            // ── العملاء ──
            'manage-customers',

            // ── الموردين ──
            'manage-suppliers',

            // ── الفواتير ──
            'manage-invoices',

            // ── إدارة المستخدمين ──
            'manage-users',

            // ── نقاط البيع ──
            'manage-pos-registers',

            // ── واجهة الكاشير ──
            'access-pos-interface',
        ];

        // إنشاء جميع الصلاحيات
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // ══════════════════════════════════════════════════════
        //  إنشاء الأدوار وربط الصلاحيات
        // ══════════════════════════════════════════════════════

        // ── 1. مدير النظام (super-admin) — كل الصلاحيات ──
        $superAdmin = Role::firstOrCreate(['name' => 'super-admin']);
        $superAdmin->givePermissionTo(Permission::all());

        // ── 2. مدير الفرع (branch-manager) ──
        $branchManager = Role::firstOrCreate(['name' => 'branch-manager']);
        $branchManager->givePermissionTo([
            'manage-departments',
            'manage-items',
            'manage-employees',
            'view-orders',
            'manage-orders',
            'view-reports',
            'view-accounting',
            'manage-customers',
        ]);

        // ── 3. المحاسب (accountant) ──
        $accountant = Role::firstOrCreate(['name' => 'accountant']);
        $accountant->givePermissionTo([
            'view-accounting',
            'manage-accounting',
            'view-reports',
            'view-audit-log',
            'manage-invoices',
            'manage-customers',
            'manage-suppliers',
            'manage-pos-registers',
            'access-pos-interface',
        ]);

        // ── 4. الكاشير (cashier) ──
        $cashier = Role::firstOrCreate(['name' => 'cashier']);
        $cashier->givePermissionTo([
            'view-orders',
            'manage-orders',
            'manage-customers',
            'manage-invoices',
        ]);

        // ── 5. موظف الضيافة (hospitality) ──
        $hospitality = Role::firstOrCreate(['name' => 'hospitality']);
        $hospitality->givePermissionTo([
            'view-orders',
            'manage-orders',
            'manage-customers',
        ]);

        // ── 6. موظف القسم (dept-staff) ──
        $deptStaff = Role::firstOrCreate(['name' => 'dept-staff']);
        $deptStaff->givePermissionTo([
            'view-orders',
        ]);

        // ══════════════════════════════════════════════════════
        //  طباعة ملخص للتأكد
        // ══════════════════════════════════════════════════════

        $this->command->info('✅ تم إنشاء الصلاحيات والأدوار بنجاح!');
        $this->command->newLine();

        $this->command->info('📋 ملخص الصلاحيات (' . Permission::count() . '):');
        foreach (Permission::all() as $perm) {
            $this->command->info("   • {$perm->name}");
        }

        $this->command->newLine();
        $this->command->info('🎭 ملخص الأدوار (' . Role::count() . '):');
        foreach (Role::all() as $role) {
            $permCount = $role->permissions->count();
            $this->command->info("   • {$role->name} ({$permCount} صلاحية)");
        }
    }
}
