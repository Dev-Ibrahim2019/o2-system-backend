<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
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
            'create-orders',
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
            'view-customers',
            'create-customers',
            'manage-customers',

            // ── الموردين ──
            'manage-suppliers',

            // ── الفواتير ──
            'manage-invoices',
            'close-invoices',
            'add-payments',
            'manage-payments',
            'post-journal',

            // ── إدارة المستخدمين ──
            'manage-users',

            // ── نقاط البيع ──
            'manage-pos-registers',

            // ── أجهزة الضيافة ──
            'manage-hospitality-devices',

            // ── القاعات والطاولات ──
            'manage-dining-zones',

            // ── واجهة الكاشير ──
            'access-pos',
            'view-menu',
            'access-pos-interface',

            // ── الكول سنتر ──
            'access-call-center',
            'manage-call-center',
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
            'create-orders',
            'manage-orders',
            'manage-customers',
            'manage-invoices',
            'close-invoices',
            'add-payments',
            'manage-payments',
            'access-pos',
            'access-pos-interface',
            'view-menu',
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
        $callCenter = Role::firstOrCreate(['name' => 'call-center']);
        $callCenter->syncPermissions([
            'access-pos',
            'access-pos-interface',
            'view-menu',
            'create-orders',
            'view-orders',
            'close-invoices',
            'add-payments',
            'view-customers',
            'create-customers',
            'manage-customers',
            'access-call-center',
            'manage-call-center',
        ]);

        $defaultBranchId = \App\Models\Branch::query()->orderBy('id')->value('id');
        $callCenterUser = User::withoutGlobalScopes()->updateOrCreate(
            ['email' => 'callcenter@o2.local'],
            [
                'name' => 'Call Center Agent',
                'username' => 'callcenter',
                'password' => Hash::make('password'),
                'branch_id' => $defaultBranchId,
            ],
        );
        $callCenterUser->syncRoles(['call-center']);
    }
}
