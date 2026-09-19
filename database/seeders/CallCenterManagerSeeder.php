<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * دور "رئيس الكول سنتر" (Call Center Manager) — دور جديد منفصل عن دور "call-center" العادي
 * (الذي يبقى دور موظف الكول سنتر العادي دون أي تغيير). صلاحية manage-call-center-employees
 * الجديدة حصرية لهذا الدور فقط — لا تُمنح لدور call-center العادي أبدًا، وإلا فقد موظف الكول
 * سنتر العادي يصل لإدارة الموظفين وهذا بالضبط ما يجب منعه.
 */
class CallCenterManagerSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $manageEmployees = Permission::firstOrCreate(['name' => 'manage-call-center-employees']);

        $manager = Role::firstOrCreate(['name' => 'call-center-manager']);
        $manager->givePermissionTo($manageEmployees);

        // القائمة المقفلة القابلة للمنح لموظف كول سنتر عادي (مصدر الحقيقة الوحيد:
        // CallCenterTeamController::AGENT_PERMISSIONS) — المدير يملكها كلها دائمًا؛ الموظف
        // العادي يبدأ بلا شيء ويُمنح فرديًا (راجع CallCenterTeamController::DEFAULT_PERMISSIONS).
        $agentPermissions = collect(\App\Http\Controllers\Api\CallCenterTeamController::AGENT_PERMISSIONS)
            ->map(fn (string $name) => Permission::firstOrCreate(['name' => $name]));

        $manager->givePermissionTo($agentPermissions);
    }
}
