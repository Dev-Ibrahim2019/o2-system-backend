<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    // أول Schedule:: بهذا المشروع — يحتاج crontab فعلي على السيرفر
    // (`* * * * * php artisan schedule:run`) عشان يعمل فعليًا، خارج نطاق الكود.
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('orders:execute-scheduled')->everyMinute();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

        // ── تسجيل middleware aliases ──
        $middleware->alias([
            'role'       => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'check.pos.network' => \App\Http\Middleware\CheckPosNetwork::class,
            'check.hospitality.network' => \App\Http\Middleware\CheckHospitalityNetwork::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // رسالة عربية واضحة بدل رسالة Spatie الإنجليزية الافتراضية — تُغطي كل الـ routes المحمية
        // بـ role/permission/role_or_permission middleware بالمشروع كامل، وليس فقط الجديدة منها.
        $exceptions->render(function (\Spatie\Permission\Exceptions\UnauthorizedException $e, \Illuminate\Http\Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا تملك الصلاحية اللازمة للوصول إلى هذا المورد.',
                    'data' => null,
                    'errors' => null,
                ], 403);
            }
        });

        // نفس التوحيد لكل حالات FormRequest::authorize() === false بكامل المشروع (مثل
        // StoreCallCenterOrderRequest) — Handler::prepareException() يحوّل AuthorizationException
        // إلى AccessDeniedHttpException قبل مطابقة أي render() مسجَّل، فالتسجيل هنا يجب أن يكون
        // على الشكل المحوَّل (Symfony) وليس على AuthorizationException نفسها.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e, \Illuminate\Http\Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا تملك الصلاحية اللازمة لتنفيذ هذا الإجراء.',
                    'data' => null,
                    'errors' => null,
                ], 403);
            }
        });
    })->create();
