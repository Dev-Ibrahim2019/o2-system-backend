<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * `crm-staff` — a generic CRM team-member role, for CRM work that isn't
 * specifically call-center (the only "plain CRM operator" role that existed
 * until now). Starts with exactly call-center's current crm.* baseline —
 * copied dynamically, not hardcoded, so it can never silently drift from
 * what call-center actually holds — and is meant to be fine-tuned per person
 * afterward through the delegated CRM staff-permissions screen
 * (CrmStaffPermissionController), not by editing this migration again.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $callCenter = Role::where('name', 'call-center')->first();
        $baseline = $callCenter
            ? $callCenter->permissions()->where('name', 'like', 'crm.%')->pluck('name')
            : collect();

        $role = Role::findOrCreate('crm-staff', 'web');
        $role->syncPermissions($baseline);
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::where('name', 'crm-staff')->where('guard_name', 'web')->delete();
    }
};
