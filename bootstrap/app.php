<?php

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
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

        // تسجيل نشاط عام: كل طلب API يغيّر شي (POST/PUT/PATCH/DELETE) —
        // مين عمله، IP تاعو، ومتى — بجدول activity_logs.
        $middleware->api(append: [
            \App\Http\Middleware\LogActivity::class,
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
        //
    })->create();
