<?php

namespace App\Services\CallCenter;

use App\Models\Order;
use App\Models\OrderActivityLog;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * الخدمة المركزية لتوثيق تغييرات حالة الطلب (Audit Log) وإدارة الإغلاق/إعادة الفتح الصريحين.
 *
 * تصميم متعمّد: لا تُعيد كتابة منطق الانتقال الموجود أصلاً بكل مكان (confirm/serve/assignDelivery/
 * markDelivered/cancel) — تلك القواعد (مثلاً "لا يُرسَل طلب كول سنتر للمطبخ قبل الدفع الكامل")
 * صحيحة ومُختبرة فعليًا وأدق من أي جدول انتقالات عام. هاي الخدمة توثّق النتيجة بعد كل تغيير
 * (logStatusChange/logPayment/logDriverAssigned...)، وتملك حصريًا منطق "الإغلاق الصريح" الجديد
 * (closed كقيمة status حقيقية بدل استنتاج ضمني) وإعادة الفتح — لأنها منطق جديد بالكامل لا يوجد
 * كود قديم يجب الحفاظ عليه فيه.
 */
class OrderStatusService
{
    private const COMPLETED_STATUSES = ['served', 'DELIVERED'];

    /**
     * خريطة انتقالات الحالة المسموحة — لطلبات الكول سنتر فقط (source='call_center'). عمداً لا
     * تغطي orders.status لكل الأنظمة: تذاكر المطبخ (ProductionTicketController) وإغلاق الـPOS
     * (SettlementEngine) وطلبات العميل الذاتية (CustomerOrderController/CustomerPortalController)
     * أنظمة منفصلة بقواعد سابقة لهذا العمل، توحيدها معًا قرار منتج منفصل خارج هذا النطاق.
     * هاي الخريطة شبكة أمان إضافية فوق الشروط الخاصة بكل إجراء بالكنترولر (اللي فيها منطق أعمق
     * مثل اشتراط الدفع الكامل بـ confirm) — لا تُلغيها، توثّق دورة الحياة الكاملة بمكان واحد فقط.
     */
    private const CALL_CENTER_TRANSITIONS = [
        'pending' => ['pending_confirmation', 'confirmed', 'paid', 'cancelled'],
        'pending_confirmation' => ['confirmed', 'paid', 'cancelled'],
        // 'ready' مضافة هون (وليست فقط عبر in_progress) — نظام "تتبع حالة الطلب" الجديد يعامل
        // confirmed وin_progress كلاهما كمرحلة PREPARING واحدة؛ markReady() يجب يشتغل من أي منهما.
        'confirmed' => ['paid', 'in_progress', 'ready', 'cancelled'],
        'paid' => ['confirmed', 'in_progress', 'ready'],
        'in_progress' => ['ready', 'served'],
        'ready' => ['served', 'OUT_FOR_DELIVERY'],
        'OUT_FOR_DELIVERY' => ['DELIVERED', 'ready'],
        'served' => ['closed'],
        'DELIVERED' => ['closed'],
    ];

    /**
     * يتحقق أن الانتقال المطلوب مسموح ضمن دورة حياة طلب الكول سنتر — يرمي استثناء لو لا.
     * لا تأثير لطلبات غير الكول سنتر (source != call_center) — خارج نطاق هذا المدقق عمدًا.
     */
    public function assertTransition(Order $order, string $toStatus): void
    {
        if ($order->source !== 'call_center') {
            return;
        }

        $from = $order->status;
        $allowed = self::CALL_CENTER_TRANSITIONS[$from] ?? [];

        if (! in_array($toStatus, $allowed, true)) {
            throw new InvalidArgumentException("انتقال حالة غير صالح للطلب #{$order->id}: {$from} ← {$toStatus}.");
        }
    }

    public function logStatusChange(Order $order, ?string $fromStatus, string $toStatus, string $actionType = 'status_change', ?string $note = null): void
    {
        OrderActivityLog::create([
            'order_id' => $order->id,
            'actor_id' => Auth::id(),
            'action_type' => $actionType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    public function logPayment(Order $order, float $amount, string $method): void
    {
        OrderActivityLog::create([
            'order_id' => $order->id,
            'actor_id' => Auth::id(),
            'action_type' => 'payment',
            'note' => "دفعة {$amount} عبر {$method}",
            'created_at' => now(),
        ]);
    }

    public function logDriverAssigned(Order $order, string $driverName): void
    {
        OrderActivityLog::create([
            'order_id' => $order->id,
            'actor_id' => Auth::id(),
            'action_type' => 'driver_assigned',
            'note' => "تعيين السائق: {$driverName}",
            'created_at' => now(),
        ]);
    }

    public function logDriverUnassigned(Order $order, string $driverName): void
    {
        OrderActivityLog::create([
            'order_id' => $order->id,
            'actor_id' => Auth::id(),
            'action_type' => 'driver_unassigned',
            'note' => "إلغاء تعيين السائق: {$driverName}",
            'created_at' => now(),
        ]);
    }

    /**
     * يُستدعى بعد أي حدث ممكن يُكمِل شرطي الإغلاق (اكتمال تشغيلي served/DELIVERED + فاتورة مدفوعة
     * بالكامل) — يضبط status='closed' صراحة لو الشرطان تحققا معًا. Idempotent: آمن يُستدعى بأي
     * وقت، ما يعمل شي لو الطلب مو مؤهّل أو مقفول/ملغى أصلاً.
     */
    public function maybeAutoClose(Order $order): void
    {
        $order->refresh();
        if (in_array($order->status, ['closed', 'cancelled', 'CANCELLED'], true)) {
            return;
        }
        if (! in_array($order->status, self::COMPLETED_STATUSES, true)) {
            return;
        }

        $invoiceStatus = $order->invoice()->value('status');
        if ($invoiceStatus !== 'paid') {
            return;
        }

        $fromStatus = $order->status;
        $order->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => Auth::id()]);
        $this->logStatusChange($order, $fromStatus, 'closed', 'closed', 'إغلاق تلقائي — اكتمل التسليم والدفع بالكامل');
    }

    /** إعادة فتح طلب مغلق — إجراء إداري صريح فقط، يتطلب سبب إلزامي. التحقق من الصلاحية مسؤولية
     * الكنترولر (نفس نمط canEditClosedOrder الموجود أصلاً بـ OrderController). */
    public function reopen(Order $order, string $reason): Order
    {
        if ($order->status !== 'closed') {
            throw new InvalidArgumentException('الطلب غير مغلق أصلاً.');
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException('سبب إعادة الفتح إلزامي.');
        }

        $closeLog = OrderActivityLog::where('order_id', $order->id)
            ->where('action_type', 'closed')
            ->orderByDesc('id')
            ->first();

        $restoredStatus = ($closeLog && in_array($closeLog->from_status, self::COMPLETED_STATUSES, true))
            ? $closeLog->from_status
            : 'served';

        $order->update([
            'status' => $restoredStatus,
            'reopened_at' => now(),
            'reopen_reason' => $reason,
        ]);

        $this->logStatusChange($order, 'closed', $restoredStatus, 'reopened', $reason);

        return $order->fresh();
    }

    /**
     * إتمام قسري (Force Complete) — استثناء إداري صريح للحالات الحدّية (القسم 7 بالبرومبت،
     * مثال: تسوية نقدية تمت خارج النظام التشغيلي المعتاد لكن سُجّلت بالفعل كفاتورة مدفوعة).
     * يتخطى الشرط التشغيلي المعتاد (served/DELIVERED عبر المسار الطبيعي) — يعمل من أي حالة نشطة —
     * لكنه **لا يتخطى شرط الدفع أبدًا** (القسم 31: رفض صريح لو الفاتورة غير مدفوعة بالكامل)، حتى لا
     * يُغلق طلب غير مسدَّد فعليًا بالنظام دون أثر. يتطلب سبب إلزامي ويُسجَّل بنوع منفصل
     * (force_completed) يميّزه عن الإغلاق التلقائي بسجل النشاط.
     */
    public function forceComplete(Order $order, string $reason): Order
    {
        if (in_array($order->status, ['closed', 'cancelled', 'CANCELLED'], true)) {
            throw new InvalidArgumentException('الطلب مغلق أو ملغى بالفعل.');
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException('سبب الإتمام القسري إلزامي.');
        }

        $invoiceStatus = $order->invoice()->value('status');
        if ($invoiceStatus !== 'paid') {
            throw new InvalidArgumentException('لا يمكن إتمام هذا الطلب لأن الدفع لا يزال معلّقًا.');
        }

        $fromStatus = $order->status;
        $order->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => Auth::id()]);
        $this->logStatusChange($order, $fromStatus, 'closed', 'force_completed', $reason);

        return $order->fresh();
    }
}
