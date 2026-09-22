<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Fired when a call-center agent searches up a customer whose birthday
 * (customer_occasions, occasion_type='birthday') falls on today — search is
 * the trigger rather than a scheduled sweep because the scheduler on this
 * deployment has no OS-level trigger wired up (see
 * CrmOrderDelayAlertService's own doc comment on the same limitation); a
 * real search from a real agent fires reliably regardless.
 *
 * Database channel only, same as every other notification in this project —
 * no broadcast/websocket layer, the bell polls the notifications table.
 */
class CustomerBirthdayTodayNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $customerId,
        public readonly string $customerName,
    ) {}

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'customer_id' => $this->customerId,
            'customer_name' => $this->customerName,
            'action' => 'customer_birthday_today',
            'message' => "اليوم عيد ميلاد العميل {$this->customerName} 🎂",
            'url' => "/admin/crm/customers/{$this->customerId}",
        ];
    }
}
