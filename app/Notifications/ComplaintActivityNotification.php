<?php

namespace App\Notifications;

use App\Models\CustomerComplaint;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * One notification type for every move on a complaint that someone else needs
 * to know about: an agent took it, resolved it or cancelled it; a manager
 * reassigned it to or away from you.
 *
 * Database channel only — the project has no broadcast/websocket layer, so the
 * bell polls this table. The payload is self-contained (title, actor, a ready
 * Arabic sentence, a deep link) so the frontend renders it without a second
 * request.
 */
class ComplaintActivityNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $complaintId,
        public readonly string $complaintTitle,
        public readonly string $action,
        public readonly string $actorName,
        public readonly string $message,
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
            'complaint_id' => $this->complaintId,
            'complaint_title' => $this->complaintTitle,
            'action' => $this->action,
            'actor_name' => $this->actorName,
            'message' => $this->message,
            'url' => "/admin/crm/complaints/{$this->complaintId}",
        ];
    }
}
