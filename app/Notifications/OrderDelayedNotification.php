<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Fired once per (order, threshold) pair by CrmOrderDelayAlertService when an
 * active order's elapsed time crosses the currently configured CRM alert
 * threshold (see CrmOrderDelaySetting). Database channel only, same as
 * ComplaintActivityNotification — no broadcast/websocket layer in this
 * project, the bell polls the notifications table.
 */
class OrderDelayedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $orderId,
        public readonly string $orderNumber,
        public readonly int $elapsedMinutes,
        public readonly int $thresholdMinutes,
        public readonly ?string $branchName,
    ) {}

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        $branch = $this->branchName ? " ({$this->branchName})" : '';

        return [
            'order_id' => $this->orderId,
            'order_number' => $this->orderNumber,
            'elapsed_minutes' => $this->elapsedMinutes,
            'threshold_minutes' => $this->thresholdMinutes,
            'action' => 'order_delayed',
            'message' => "الطلب {$this->orderNumber}{$branch} تجاوز {$this->thresholdMinutes} دقيقة منذ إنشائه (منذ {$this->elapsedMinutes} دقيقة).",
            'url' => '/admin/crm/orders/delayed',
        ];
    }
}
