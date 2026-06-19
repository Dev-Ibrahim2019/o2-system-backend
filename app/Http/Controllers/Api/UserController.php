<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class UserController extends ApiController
{
    // جلب قائمة المستخدمين مع أدوارهم
    public function index()
    {
        $users = User::select('id', 'name', 'username', 'email')
            ->with('roles:id,name')
            ->get()
            ->map(function ($user) {
                return [
                    'id'       => $user->id,
                    'name'     => $user->name,
                    'username' => $user->username,
                    'email'    => $user->email,
                    'role'     => $user->roles->first()?->name ?? 'no-role',
                    'is_active' => !$user->trashed(),
                ];
            });

        return $this->success('Users fetched', $users);
    }

    // جلب كل الأدوار المتاحة في النظام
    public function roles()
    {
        $roles = Role::select('id', 'name')->get();
        return $this->success('Roles fetched', $roles);
    }

    // تحديث دور مستخدم معين
    public function updateRole(Request $request, User $user)
    {
        $request->validate([
            'role' => 'required|string|exists:roles,name',
        ]);

        $user->syncRoles([$request->role]);

        return $this->success('Role updated', [
            'id'   => $user->id,
            'name' => $user->name,
            'role' => $request->role,
        ]);
    }

    // تفعيل/تعطيل حساب المستخدم
    public function toggleStatus(User $user)
    {
        // نستخدم الحقل المعتاد: is_active (إذا كان موجوداً) أو نعتمد على_SOFT_DELETE
        // للبساطة: نُعلّم المستخدم بـ deleted_at (Soft Delete)
        if ($user->trashed()) {
            $user->restore();
            $status = true;
        } else {
            $user->delete();
            $status = false;
        }

        return $this->success('Status toggled', [
            'id'        => $user->id,
            'name'      => $user->name,
            'is_active' => $status,
        ]);
    }
}
