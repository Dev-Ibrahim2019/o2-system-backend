<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\CallCenter\OrderConfirmationService;
use App\Services\Printing\OrderPrintingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * ينفّذ الطلبات المجدولة (status=scheduled) اللي حان موعدها — نفس منطق زر "-"/التنفيذ اليدوي
 * حرفيًا (عبر OrderConfirmationService المشتركة)، ثم يطبع تذاكر الأقسام فعليًا (OrderPrintingService
 * لا يعتمد على HTTP/session — آمن تمامًا من سياق console، تم التحقق).
 *
 * يُسجَّل عبر Schedule::command('orders:execute-scheduled')->everyMinute() بـ bootstrap/app.php.
 * يحتاج crontab فعلي على السيرفر (`* * * * * php artisan schedule:run`) — خارج نطاق الكود.
 */
class ExecuteScheduledOrders extends Command
{
    protected $signature = 'orders:execute-scheduled';
    protected $description = 'ينفّذ (إرسال للأقسام + طباعة) الطلبات المجدولة التي حان موعدها';

    public function handle(OrderConfirmationService $confirmationService, OrderPrintingService $printingService): int
    {
        $orders = Order::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->whereNull('executed_at')
            ->where('execution_attempts', '<', 3)
            ->get();

        if ($orders->isEmpty()) {
            $this->info('لا توجد طلبات مجدولة مستحقة الآن.');

            return self::SUCCESS;
        }

        $succeeded = 0;
        $failed = 0;

        foreach ($orders as $order) {
            try {
                $confirmationService->confirmOrder($order);
                $printingService->printTickets($order->fresh());

                $order->update([
                    'executed_at' => now(),
                    'execution_failed_reason' => null,
                ]);

                $succeeded++;
                $this->info("تم تنفيذ الطلب #{$order->id}");
            } catch (\Throwable $e) {
                // فشل ناتج عن قاعدة عمل (مثلاً طلب غير مدفوع) لن يُحله إعادة المحاولة — نوقف
                // المحاولات فورًا بدل حرق 3 دقائق بلا فائدة؛ فشل عابر (طابعة/شبكة) يُعاد المحاولة
                // عليه تلقائيًا بالتشغيلة القادمة حتى الحد الأقصى.
                $isBusinessRuleFailure = $e instanceof InvalidArgumentException;

                $order->update([
                    'execution_attempts' => $isBusinessRuleFailure ? 3 : $order->execution_attempts + 1,
                    'execution_failed_reason' => $e->getMessage(),
                ]);

                Log::error('فشل التنفيذ التلقائي للطلب المجدول #' . $order->id, [
                    'message' => $e->getMessage(),
                    'attempts' => $order->execution_attempts,
                    'business_rule_failure' => $isBusinessRuleFailure,
                ]);

                $failed++;
                $this->error("فشل تنفيذ الطلب #{$order->id}: {$e->getMessage()}");
            }
        }

        $this->info("النتيجة: {$succeeded} نجح، {$failed} فشل.");

        return self::SUCCESS;
    }
}
