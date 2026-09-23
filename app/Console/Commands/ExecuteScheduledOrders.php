<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\CallCenter\CallCenterOrderExecutionService;
use App\Services\CallCenter\OrderConfirmationService;
use App\Services\CallCenter\OrderSlotService;
use App\Services\Printing\OrderPrintingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

        $succeeded = 0;
        $failed = 0;

        // طلبات الكول سنتر المدفوعة مسبقًا والمجدولة (scheduled_at بدون status=scheduled): بتنفّذ من نفس
        // مسار التنفيذ الفوري (إنشاء تذاكر الأقسام + طباعتها + تعليم executed_at بنفس الـtransaction).
        [$paidSucceeded, $paidFailed] = $this->executePaidScheduledCallCenterOrders();
        $succeeded += $paidSucceeded;
        $failed += $paidFailed;

        if ($orders->isEmpty()) {
            $this->info($succeeded + $failed === 0 ? 'لا توجد طلبات مجدولة مستحقة الآن.' : "النتيجة: {$succeeded} نجح، {$failed} فشل.");

            return self::SUCCESS;
        }

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

                // execution_failed_reason عمود VARCHAR(255) — بعض الاستثناءات (مثلاً فشل
                // Browsershot عند الطباعة) رسالتها أطول من ذلك بكثير، وحفظها كما هي يرمي
                // SQLSTATE[22001] من داخل catch نفسه ويُسقط تشغيلة الأمر كلها (exit code 1)
                // بدل التعامل مع فشل طلب واحد بمعزل عن الباقي. النص الكامل يبقى بالـ Log أدناه.
                $order->update([
                    'execution_attempts' => $isBusinessRuleFailure ? 3 : $order->execution_attempts + 1,
                    'execution_failed_reason' => Str::limit($e->getMessage(), 250, ''),
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

    /** @return array{0:int,1:int} [نجح، فشل] */
    private function executePaidScheduledCallCenterOrders(): array
    {
        $service = app(CallCenterOrderExecutionService::class);
        $succeeded = 0;
        $failed = 0;

        $due = Order::query()->withoutGlobalScopes()
            ->where('source', 'call_center')
            ->where('status', '!=', 'scheduled')
            ->whereNotIn('status', OrderSlotService::TERMINAL_STATUSES)
            ->where('payment_status', Order::PAYMENT_STATUS_PAID)
            ->whereIn('kitchen_release_status', [Order::KITCHEN_RELEASE_STATUS_HELD, Order::KITCHEN_RELEASE_STATUS_FAILED])
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->whereNull('executed_at')
            ->where('execution_attempts', '<', 3)
            ->get();

        foreach ($due as $order) {
            try {
                $result = $service->executeNow($order, null, clearSchedule: false);
                if ($result->kitchen_release_status !== Order::KITCHEN_RELEASE_STATUS_RELEASED) {
                    throw new \RuntimeException('تعذّر إرسال الطلب للأقسام');
                }
                $succeeded++;
                $this->info("تم تنفيذ الطلب المجدول #{$order->id}");
            } catch (\Throwable $e) {
                $order->update([
                    'execution_attempts' => (int) $order->execution_attempts + 1,
                    'execution_failed_reason' => Str::limit($e->getMessage(), 250, ''),
                ]);
                Log::error('فشل التنفيذ التلقائي للطلب المجدول (مدفوع) #'.$order->id, ['message' => $e->getMessage()]);
                $failed++;
                $this->error("فشل تنفيذ الطلب #{$order->id}: {$e->getMessage()}");
            }
        }

        return [$succeeded, $failed];
    }
}
