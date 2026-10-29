<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * أدوار "محصورة" بجزء واحد من الـ API (حالياً storekeeper → /api/inventory/*).
 *
 * أغلب مسارات الـ API محمية بـ auth:sanctum فقط بلا فحص دور، فأي مستخدم مسجّل يصل إليها.
 * بدلاً من إضافة permission لعشرات المسارات، هذا الـ middleware (مسجّل على مجموعة api كلها)
 * يرفض بـ 403 أي طلب خارج المسموح إذا كانت **كل** أدوار المستخدم محصورة.
 * من يملك دوراً آخر معها (admin + storekeeper مثلاً) لا يتأثر.
 */
class RestrictScopedRoles
{
    /** الدور → أنماط المسارات المسموحة (بصيغة Request::is بدون بادئة api/). */
    public const SCOPES = [
        'storekeeper' => ['inventory', 'inventory/*'],
    ];

    /** مسموحة لكل الأدوار المحصورة: الجلسة نفسها. */
    private const COMMON = ['login', 'logout', 'auth/me'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('sanctum')->user();
        if (! $user || ! method_exists($user, 'getRoleNames')) {
            return $next($request);
        }

        $roles = $user->getRoleNames()->all();
        if ($roles === [] || array_diff($roles, array_keys(self::SCOPES)) !== []) {
            return $next($request);
        }

        $patterns = self::COMMON;
        foreach ($roles as $role) {
            $patterns = array_merge($patterns, self::SCOPES[$role]);
        }

        if ($request->is(...array_map(fn (string $p) => 'api/'.$p, $patterns))) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'لا تملك الصلاحية اللازمة للوصول إلى هذا المورد.',
            'data' => null,
            'errors' => null,
        ], 403);
    }
}
