<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fixes a gap the previous migration's own live testing caught: every route
 * under /api/crm sits behind `Route::middleware(['auth:sanctum',
 * 'permission:crm.access'])->prefix('crm')` — a gate above and separate from
 * any individual crm.* permission. accountant was given crm.loyalty.manage
 * but, verified live, still got 403 on every /crm/loyalty/* route, because
 * the role never held crm.access at all (confirmed: it holds none of
 * crm.view-customers, crm.access, or any other crm.* permission today).
 *
 * Granting crm.loyalty.manage was correct per the prompt; it was simply
 * insufficient on its own. This grants the outer gate to accountant alone —
 * not to any other role — so accountant's reach into /crm stays exactly as
 * narrow as loyalty management requires. call-center and crm-manager already
 * hold crm.access from earlier migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::where('name', 'accountant')->first();
        if ($role) {
            $role->givePermissionTo('crm.access');
        }
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::where('name', 'accountant')->first();
        if ($role) {
            $role->revokePermissionTo('crm.access');
        }
    }
};
