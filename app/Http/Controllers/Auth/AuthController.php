<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\ApiController;
use App\Http\Requests\V1\LoginUserRequest;
use App\Models\User;
use App\Models\PosRegister;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends ApiController
{
    public function login(LoginUserRequest $request)
    {
        $request->validated($request->all());

        if (! Auth::attempt($request->only('username', 'password'))) {
            return $this->error('Invalid Credentials', 401);
        }

        $user = User::firstWhere('username', $request->username);

        // ═══════════════════════════════════════════════════════════
        //  🛡️ فحص (1): تقييد دخول الكاشيرات حسب الفرع (Branch Access)
        //  إذا كان الجهاز مرسلاً X-Device-UUID، نتحقق من أن المستخدم
        //  ينتمي لنفس فرع الجهاز (ما عدا الأدمن)
        // ═══════════════════════════════════════════════════════════
        $deviceUuid = $request->header('X-Device-UUID');

        if ($deviceUuid) {
            // 1. جلب نقطة البيع المربوطة بهذا الجهاز
            $posRegister = PosRegister::where('device_uuid', $deviceUuid)->first();

            // 2. إذا وجد السجل والجهاز مفعّل، قارن الفروع
            if ($posRegister && $posRegister->status === 'ACTIVE') {
                // 3. المستثنى الوحيد: الأدمن (super-admin) له حق الدخول من أي جهاز
                if (!$user->hasRole('super-admin')) {
                    // 4. مقارنة branch_id الخاص بالمستخدم مع branch_id الخاص بالجهاز
                    if ((int) $user->branch_id !== (int) $posRegister->branch_id) {
                        Auth::logout(); // إلغاء تسجيل الدخول
                        return response()->json([
                            'success' => false,
                            'message' => 'عذراً، أنت لا تنتمي لهذا الفرع! لا يمكنك استخدام هذا الجهاز.',
                        ], 403);
                    }
                }
            }
        }

        return $this->ok(
            'Authenticated',
            [
                'user'        => [
                    'id'        => $user->id,
                    'name'      => $user->name,
                    'username'  => $user->username,
                    'email'     => $user->email,
                    'branch_id' => $user->branch_id,
                ],
                'token'       => $user->createToken('Api token for ' . $user->username)->plainTextToken,
                'roles'       => $user->getRoleNames()->toArray(),
                'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
            ]
        );
    }

    public function logout(Request $request)
    {
        if ($request->user() && $request->user()->currentAccessToken()) {
            $request->user()->currentAccessToken()->delete();
            return $this->ok('User logged out');
        }
        return $this->error('Not authenticated', 401);
    }

    public function register()
    {
        // return $this->ok('register');
    }
}
