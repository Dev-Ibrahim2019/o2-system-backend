<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Establishes the `crm-manager` role in the existing Spatie authorization
 * system (guard 'web' — the guard every other role/permission in this app
 * already uses; config('auth.defaults.guard') === 'web', and config/auth.php
 * only defines a 'web' guard tied to the User model's provider).
 *
 * Also removes a broken, orphaned 'crm-manger' role (typo'd name, and
 * created under guard 'sanctum' by RoleController::store() before that
 * endpoint explicitly set guard_name — see the fix in that controller).
 * That row had zero user assignments and zero permission assignments, so
 * it is dead data, not a role anyone is depending on.
 *
 * No new permissions are created: all CRM permissions the crm-manager role
 * needs to use the CRM module's existing routes already exist (seeded by
 * 2026_07_27_100001_add_crm_customer_permissions.php and earlier CRM route
 * work) — this migration only assigns the existing crm.* permission set to
 * the new role.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Clean up the broken row from the earlier guard-mismatch bug.
        // Confirmed orphaned before deletion: 0 rows in model_has_roles,
        // 0 rows in role_has_permissions for this role id.
        Role::where('name', 'crm-manger')->where('guard_name', 'sanctum')->delete();

        $role = Role::findOrCreate('crm-manager', 'web');

        $crmPermissions = Permission::where('guard_name', 'web')
            ->where('name', 'like', 'crm.%')
            ->pluck('name');

        $role->syncPermissions($crmPermissions);
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::where('name', 'crm-manager')->where('guard_name', 'web')->delete();
    }
};
