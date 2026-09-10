<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Separates "work a complaint" from "decide who works it".
 *
 * crm.complaints.update lets an agent move a complaint they hold through its
 * lifecycle — followups, status, priority, department, resolution. It does
 * NOT let them change assigned_to: once a complaint is on someone it stays
 * there until it's resolved; they can't hand it back or push it to a peer.
 *
 * crm.complaints.assign is the manager's key — assigning an unassigned
 * complaint, and re-assigning one that's already on someone. Granted to
 * super-admin and crm-manager only, matching how crm.loyalty.manage was
 * scoped (owner of the CRM module, not the general agent).
 */
return new class extends Migration
{
    private string $permission = 'crm.complaints.assign';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate($this->permission, 'web');

        Role::whereIn('name', ['super-admin', 'crm-manager'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($this->permission));
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::where('name', $this->permission)->delete();
    }
};
