<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * تسجيل عام لكل طلب يغيّر شي بالنظام: مين عملها (user_id)، IP تاعو،
 * المسار/الراوت، حالة الرد، والتاريخ والوقت — بجدول activity_logs.
 *
 * بيسجّل فقط الطلبات المؤثرة (POST/PUT/PATCH/DELETE) — القراءة (GET) ما بتنسجل
 * حتى ما يضخم الجدول من كل فتح شاشة/تحديث تلقائي.
 *
 * الكتابة تصير بـ terminate() (بعد إرسال الرد) حتى ما تأخّر أي طلب حقيقي،
 * ومحاطة بـ try/catch حتى فشل التسجيل نفسه ما يكسر الطلب.
 */
class LogActivity
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! in_array($request->method(), self::MUTATING_METHODS, true)) {
            return;
        }

        try {
            $route = $request->route();

            ActivityLog::create([
                'user_id' => $request->user()?->id,
                'branch_id' => $request->user()?->branch_id,
                'method' => $request->method(),
                'path' => $request->path(),
                'route_name' => $route?->getName(),
                'route_params' => $route instanceof Route ? $this->safeRouteParams($route) : null,
                'status_code' => $response->getStatusCode(),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('LogActivity: فشل تسجيل النشاط', ['error' => $e->getMessage()]);
        }
    }

    /**
     * معاملات الراوت بأمان — لو Route Model Binding رجّع موديل كامل، نسجّل
     * الـ id تاعو بس (مش كل الحقول)، تفاديًا لتسريب بيانات حساسة باللوق.
     */
    private function safeRouteParams(Route $route): array
    {
        $params = [];

        foreach ($route->parameters() as $key => $value) {
            $params[$key] = match (true) {
                is_object($value) && method_exists($value, 'getKey') => $value->getKey(),
                is_scalar($value) => $value,
                default => null,
            };
        }

        return $params;
    }
}
