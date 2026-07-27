<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CallCenterPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $roleId = DB::table('roles')->where('name', 'call-center')->value('id');

        $permissions = [
            'access-call-center-interface',
            'manage-call-center',
            'view-orders',
            'manage-customers',
        ];

        foreach ($permissions as $perm) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $perm],
                ['guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]
            );
            $permId = DB::table('permissions')->where('name', $perm)->value('id');
            DB::table('role_has_permissions')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permId],
                []
            );
        }
    }
}
