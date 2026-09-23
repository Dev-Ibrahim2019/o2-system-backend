<?php

namespace App\Services\CallCenter;

use App\Models\Order;

/**
 * حالة تدفق طلب الكول سنتر بثلاث محاور مستقلة بدل حالات وراء بعض:
 *
 *   lifecycle        : open | closed | cancelled
 *   payment_state    : unpaid | paid                    (مشتقة من orders.payment_status / الفاتورة)
 *   execution_status : pending | scheduled | executed   (مشتقة من kitchen_release_status / executed_at / scheduled_at)
 *
 * عمدًا ما فيها أعمدة جديدة: payment_status وkitchen_release_status وscheduled_at وexecuted_at موجودين
 * أصلاً، وتكرارهم كأعمدة تانية بيخلق مصدرين للحقيقة. الإغلاق مسموح فقط لما paid && executed، وهاد
 * الشرط بيتفحص هون (ومنه بـ OrderClosureService) بالباك اند — مش بس بالواجهة.
 */
class OrderFlowService
{
    private const CANCELLED_STATUSES = ['cancelled', 'canceled', 'CANCELLED'];

    /** حالات fulfilment القديمة اللي معناها الطلب انبعت للأقسام فعليًا (مسارات confirm/serve اليدوية) */
    private const EXECUTED_LEGACY_STATUSES = ['confirmed', 'in_progress', 'ready', 'served', 'OUT_FOR_DELIVERY', 'DELIVERED'];

    public static function lifecycle(Order $order): string
    {
        return match (true) {
            in_array($order->status, self::CANCELLED_STATUSES, true) => 'cancelled',
            $order->status === 'closed' => 'closed',
            default => 'open',
        };
    }

    public static function paymentState(Order $order): string
    {
        if ($order->payment_status === Order::PAYMENT_STATUS_PAID) {
            return 'paid';
        }
        // طلبات قديمة انسجّل دفعها بمسار الفاتورة العام بدون ما يمرّ على markPaid()
        $invoiceStatus = $order->relationLoaded('invoice')
            ? $order->invoice?->status
            : $order->invoice()->value('status');

        return $invoiceStatus === 'paid' ? 'paid' : 'unpaid';
    }

    public static function executionStatus(Order $order): string
    {
        if ($order->kitchen_release_status === Order::KITCHEN_RELEASE_STATUS_RELEASED
            || $order->executed_at
            || in_array($order->status, self::EXECUTED_LEGACY_STATUSES, true)) {
            return 'executed';
        }

        return $order->scheduled_at ? 'scheduled' : 'pending';
    }

    /** نصوص عربية بتوضّح شو الناقص لتفعيل الإغلاق — فاضية يعني الطلب جاهز للإغلاق (أو مو مفتوح أصلاً). */
    public static function closeBlockers(Order $order): array
    {
        if (self::lifecycle($order) !== 'open') {
            return [];
        }

        $blockers = [];
        if (self::paymentState($order) !== 'paid') {
            $blockers[] = 'الدفع غير مكتمل';
        }
        if (self::executionStatus($order) !== 'executed') {
            $blockers[] = $order->scheduled_at ? 'الطلب مجدول ولم يُنفَّذ بعد' : 'الطلب لم يُنفَّذ بعد';
        }

        return $blockers;
    }

    public static function describe(Order $order): array
    {
        $blockers = self::closeBlockers($order);

        return [
            'lifecycle' => self::lifecycle($order),
            'payment_state' => self::paymentState($order),
            'execution_status' => self::executionStatus($order),
            'ready_to_close' => self::lifecycle($order) === 'open' && $blockers === [],
            'close_blockers' => $blockers,
        ];
    }
}
