<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleController extends ApiController
{
    // جلب كل الأدوار مع صلاحياتها
    public function index()
    {
        $roles = Role::with('permissions:id,name')
            ->get()
            ->map(function ($role) {
                return [
                    'id'          => $role->id,
                    'name'        => $role->name,
                    'permissions' => $role->permissions->pluck('name')->toArray(),
                ];
            });

        return $this->success('Roles fetched', $roles);
    }

    // جلب كل الصلاحيات المتاحة في النظام
    public function getAllPermissions()
    {
        $permissions = Permission::select('id', 'name')->get();
        return $this->success('Permissions fetched', $permissions);
    }

    // إنشاء دور جديد
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:roles,name',
        ]);

        // Explicit guard_name is required here: without it, Spatie infers the
        // guard from the current route's auth middleware (this endpoint runs
        // behind auth:sanctum), which would silently create the role under
        // guard 'sanctum' — inconsistent with every existing role/permission
        // in this app, which all use 'web' (config('auth.defaults.guard')).
        // That mismatch is exactly what produced the broken 'crm-manger'
        // role and the "no permission named manage-users for guard sanctum"
        // error when trying to assign it permissions.
        $role = Role::create(['name' => $request->name, 'guard_name' => 'web']);

        return $this->success('Role created', [
            'id'          => $role->id,
            'name'        => $role->name,
            'permissions' => [],
        ], 201);
    }

    // حذف دور
    public function destroy(Role $role)
    {
        // منع حذف الدور الافتراضي
        if ($role->name === 'super-admin') {
            return $this->error('Cannot delete super-admin role', 403);
        }

        $role->delete();
        return $this->success('Role deleted', []);
    }

    // تحديث صلاحيات دور معين
    public function updatePermissions(Request $request, Role $role)
    {
        $request->validate([
            'permissions'   => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $role->syncPermissions($request->permissions);

        // إعادة جلب الصلاحيات بعد التحديث
        $role->load('permissions:id,name');

        return $this->success('Permissions updated', [
            'id'          => $role->id,
            'name'        => $role->name,
            'permissions' => $role->permissions->pluck('name')->toArray(),
        ]);
    }
}
