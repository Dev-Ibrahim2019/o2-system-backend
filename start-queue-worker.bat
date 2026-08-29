@echo off
REM ─────────────────────────────────────────────────────────────
REM  O2 System — Queue Worker (الطباعة / الطلبات الخلفية)
REM  لازم يضل شغّال دايماً وإلا "تنفيذ وطباعة" و"طباعة" ما بيطبعوا.
REM  شغّله يدوياً بالضغط عليه، أو خلّيه Scheduled Task عند تشغيل الويندوز.
REM ─────────────────────────────────────────────────────────────
cd /d "%~dp0"

:loop
echo [%date% %time%] starting queue worker...
php artisan queue:work database --queue=default --tries=2 --timeout=120 --sleep=1 --max-time=3600
echo [%date% %time%] worker exited, restarting in 3s...
timeout /t 3 /nobreak >nul
goto loop
