<?php
// app/Http/Controllers/Auth/AuthController.php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\ApiController;
use App\Http\Requests\V1\LoginUserRequest;
use App\Http\Resources\UserMeResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends ApiController
{
    // ─────────────────────────────────────────────────────────────────────────
    // POST /login
    // ─────────────────────────────────────────────────────────────────────────
    public function login(LoginUserRequest $request)
    {
        $request->validated($request->all());

        if (! Auth::attempt($request->only('email', 'password'))) {
            return $this->error('بيانات الدخول غير صحيحة', 401);
        }

        // جلب المستخدم مع الموظف والفرع والقسم
        $user = User::with([
            'employee.branch:id,name,code',
            'employee.department:id,name',
        ])->firstWhere('email', $request->email);

        $token = $user->createToken('Api token for ' . $user->email)->plainTextToken;

        return $this->ok('تم تسجيل الدخول بنجاح', [
            'token' => $token,
            'user'  => $this->buildUserPayload($user),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /logout
    // ─────────────────────────────────────────────────────────────────────────
    public function logout(Request $request)
    {
        if ($request->user() && $request->user()->currentAccessToken()) {
            $request->user()->currentAccessToken()->delete();
            return $this->ok('تم تسجيل الخروج بنجاح');
        }
        return $this->error('غير مصادق', 401);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /auth/me  — بيانات المستخدم الحالي الكاملة
    // ─────────────────────────────────────────────────────────────────────────
    public function me(Request $request)
    {
        $user = User::with([
            'employee.branch:id,name,code',
            'employee.department:id,name',
        ])->find($request->user()->id);

        return $this->ok('بيانات المستخدم', $this->buildUserPayload($user));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper — بناء كائن المستخدم الموحد للفرونت
    // ─────────────────────────────────────────────────────────────────────────
    private function buildUserPayload(User $user): array
    {
        $employee = $user->employee;

        return [
            // بيانات User
            'id'            => $user->id,
            'name'          => $employee?->name ?? $user->name,
            'email'         => $user->email,

            // بيانات الموظف (إن وُجد)
            'employee_id'   => $employee?->id,
            'employeeId'    => $employee?->employeeId,
            'phone'         => $employee?->phone,
            'image'         => $employee?->image,

            // الصلاحيات والدور
            'role'          => $employee?->role ?? 'EMPLOYEE',
            'status'        => $employee?->status ?? 'ACTIVE',
            'permissions'   => $employee?->permissions ?? [],

            // الفرع
            'branch_id'     => $employee?->branch_id,
            'branchId'      => $employee?->branch_id,   // للتوافق مع الفرونت القديم
            'branch'        => $employee?->branch ? [
                'id'   => $employee->branch->id,
                'name' => $employee->branch->name,
                'code' => $employee->branch->code,
            ] : null,

            // القسم
            'department_id' => $employee?->department_id,
            'department'    => $employee?->department ? [
                'id'   => $employee->department->id,
                'name' => $employee->department->name,
            ] : null,
        ];
    }
}
