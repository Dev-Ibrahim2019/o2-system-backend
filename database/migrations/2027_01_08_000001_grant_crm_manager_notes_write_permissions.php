<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Restores the invariant that 2027_01_03_000001_add_crm_manager_role.php
 * established: the `crm-manager` role holds EVERY `crm.*` permission (that
 * migration assigns them with a `crm.%` wildcard sync, not a hand-written
 * list).
 *
 * The invariant broke on ordering, not on intent:
 *   2027_01_03  crm-manager syncs all crm.* permissions existing at the time
 *   2027_01_07  creates crm.notes.create/update/delete and grants them to
 *               super-admin, branch-manager, call-center, accountant only
 *
 * So crm-manager — the one role dedicated to the CRM module, and one that
 * already holds crm.notes.view and crm.view-sensitive-notes — ended up able
 * to read notes but not write them: POST/PUT/DELETE
 * /api/crm/customers/{customer}/notes returned 403 for it while the feature
 * was fully implemented on both the API and the UI.
 *
 * This grants only those three already-existing permissions to that one
 * role. No permission is created, no schema changes, no other role touched.
 */
return new class extends Migration
{
    private array $permissions = [
        'crm.notes.create',
        'crm.notes.update',
        'crm.notes.delete',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::where('name', 'crm-manager')->where('guard_name', 'web')->first();

        if (! $role) {
            return;
        }

        // Only grant permissions that already exist — this migration never
        // creates them (2027_01_07_000001 owns their creation).
        $existing = Permission::where('guard_name', 'web')
            ->whereIn('name', $this->permissions)
            ->pluck('name')
            ->all();

        if ($existing !== []) {
            $role->givePermissionTo($existing);
        }
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::where('name', 'crm-manager')->where('guard_name', 'web')->first();

        $role?->revokePermissionTo(
            Permission::where('guard_name', 'web')->whereIn('name', $this->permissions)->pluck('name')->all()
        );
    }
};
