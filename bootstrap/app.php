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
        //
    })->create();
