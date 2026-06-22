<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class UserController extends ApiController
{
    // جلب قائمة المستخدمين مع أدوارهم وفروعهم
    // super-admin يرى كل المستخدمين، غيره يرى موظفي فرعه فقط (BranchScope)
    public function index()
    {
        $authUser = auth()->user();
        $query = User::select('id', 'name', 'username', 'email', 'branch_id')
            ->with(['roles:id,name', 'branch:id,name']);

        // فقط super-admin يرى كل المستخدمين
        if ($authUser->hasRole('super-admin')) {
            $query->withoutGlobalScope(\App\Models\Scopes\BranchScope::class);
        }

        $users = $query->get()
            ->map(function ($user) {
                return [
                    'id'         => $user->id,
                    'name'       => $user->name,
                    'username'   => $user->username,
                    'email'      => $user->email,
                    'role'       => $user->roles->first()?->name ?? 'no-role',
                    'branch_id'  => $user->branch_id,
                    'branch_name' => $user->branch?->name ?? '—',
                    'is_active'  => !$user->trashed(),
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

    // تحديث دور مستخدم + ربطه بفرع
    // حماية: منع مدير الفرع من تعديل موظف في فرع آخر
    public function updateRole(Request $request, User $user)
    {
        $request->validate([
            'role'      => 'required|string|exists:roles,name',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        $authUser = auth()->user();

        // حماية: إذا لم يكن super-admin، تحقق أن المستخدم المُعدَّل ينتمي لنفس الفرع
        if (! $authUser->hasRole('super-admin')) {
            if ($user->branch_id !== $authUser->branch_id) {
                return $this->error('لا تملك صلاحية تعديل موظفي الفروع الأخرى', 403);
            }
        }

        $user->syncRoles([$request->role]);
        $user->update(['branch_id' => $request->branch_id]);

        return $this->success('Role & branch updated', [
            'id'        => $user->id,
            'name'      => $user->name,
            'role'      => $request->role,
            'branch_id' => $user->branch_id,
        ]);
    }

    // إنشاء مستخدم جديد
    public function store(Request $request)
    {
        $request->validate([
            'name'      => 'required|string|max:255',
            'username'  => 'required|string|max:255|unique:users,username',
            'email'     => 'required|email|unique:users,email',
            'password'  => 'required|string|min:6',
            'role'      => 'required|string|exists:roles,name',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        $user = User::create([
            'name'      => $request->name,
            'username'  => $request->username,
            'email'     => $request->email,
            'password'  => bcrypt($request->password),
            'branch_id' => $request->branch_id,
        ]);

        $user->syncRoles([$request->role]);

        return $this->success('User created', [
            'id'         => $user->id,
            'name'       => $user->name,
            'username'   => $user->username,
            'email'      => $user->email,
            'role'       => $request->role,
            'branch_id'  => $user->branch_id,
            'branch_name'=> $user->branch?->name ?? '—',
            'is_active'  => true,
        ]);
    }

    // تعديل مستخدم
    public function update(Request $request, User $user)
    {
        $request->validate([
            'name'      => 'required|string|max:255',
            'username'  => 'required|string|max:255|unique:users,username,' . $user->id,
            'email'     => 'required|email|unique:users,email,' . $user->id,
            'password'  => 'nullable|string|min:6',
            'role'      => 'required|string|exists:roles,name',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        $data = [
            'name'      => $request->name,
            'username'  => $request->username,
            'email'     => $request->email,
            'branch_id' => $request->branch_id,
        ];

        if ($request->password) {
            $data['password'] = bcrypt($request->password);
        }

        $user->update($data);
        $user->syncRoles([$request->role]);

        return $this->success('User updated', [
            'id'         => $user->id,
            'name'       => $user->name,
            'username'   => $user->username,
            'email'      => $user->email,
            'role'       => $request->role,
            'branch_id'  => $user->branch_id,
            'branch_name'=> $user->branch?->name ?? '—',
            'is_active'  => !$user->trashed(),
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
