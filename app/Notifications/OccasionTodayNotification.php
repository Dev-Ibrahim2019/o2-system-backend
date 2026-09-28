<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * "اليوم مناسبة X للعميل/المجموعة Y" — sent once per occasion per day by
 * CrmOccasionReminderService to whoever is responsible for following it up.
 * Database channel only, read by the CRM bell like every other CRM notice.
 */
class OccasionTodayNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $occasionId,
        public readonly string $occasionTitle,
        public readonly ?string $ownerName,
        public readonly ?string $contactMethod,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $owner = $this->ownerName ? " — {$this->ownerName}" : '';
        $contact = [
            'call' => 'اتصال', 'sms' => 'رسالة نصية', 'email' => 'بريد إلكتروني', 'whatsapp' => 'واتساب',
        ][$this->contactMethod ?? ''] ?? null;

        return [
            'occasion_id' => $this->occasionId,
            'action' => 'occasion_today',
            'message' => "اليوم: {$this->occasionTitle}{$owner}"
                . ($contact ? " (طريقة التواصل المفضلة: {$contact})" : '')
                . ' 🎉',
            'url' => "/admin/crm/occasions?occasion={$this->occasionId}",
        ];
    }
}
