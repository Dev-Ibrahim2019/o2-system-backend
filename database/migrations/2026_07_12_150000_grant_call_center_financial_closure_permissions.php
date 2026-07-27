<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('roles')->where('name', 'call-center')->exists();
        if (! $exists) {
            DB::table('roles')->insert([
                'name'       => 'call-center',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $roleId = DB::table('roles')->where('name', 'call-center')->value('id');
        if ($roleId) {
            foreach (['close-invoices', 'add-payments'] as $perm) {
                $permId = DB::table('permissions')->where('name', $perm)->value('id');
                if (! $permId) {
                    $permId = DB::table('permissions')->insertGetId([
                        'name'       => $perm,
                        'guard_name' => 'web',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $hasRole = DB::table('role_has_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permId)
                    ->exists();
                if (! $hasRole) {
                    DB::table('role_has_permissions')->insert([
                        'role_id'       => $roleId,
                        'permission_id' => $permId,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('name', 'call-center')->value('id');
        if ($roleId) {
            $permIds = DB::table('permissions')
                ->whereIn('name', ['close-invoices', 'add-payments'])
                ->pluck('id');
            DB::table('role_has_permissions')
                ->where('role_id', $roleId)
                ->whereIn('permission_id', $permIds)
                ->delete();
            DB::table('roles')->where('id', $roleId)->delete();
        }
    }
};
