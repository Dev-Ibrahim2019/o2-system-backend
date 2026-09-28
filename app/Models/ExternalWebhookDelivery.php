<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/** توصيل حدث outbox واحد لتطبيق خارجي واحد (مع عدّاد المحاولات) — المحاولات نفسها بـ external_webhook_attempts. */
class ExternalWebhookDelivery extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    /** استنفد المحاولات — بيرجع pending بس بإعادة إرسال يدوية */
    public const STATUS_DEAD = 'dead';

    protected $fillable = [
        'external_api_client_id', 'integration_outbox_id', 'event_type', 'aggregate_ref', 'status',
        'attempt_count', 'next_attempt_at', 'delivered_at', 'last_status_code', 'last_error',
    ];

    protected $casts = [
        'attempt_count' => 'integer',
        'next_attempt_at' => 'datetime',
        'delivered_at' => 'datetime',
        'last_status_code' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(ExternalApiClient::class, 'external_api_client_id');
    }

    public function outbox(): BelongsTo
    {
        return $this->belongsTo(IntegrationOutbox::class, 'integration_outbox_id');
    }

    public function logAttempt(?int $statusCode, ?string $error, int $durationMs): void
    {
        DB::table('external_webhook_attempts')->insert([
            'external_webhook_delivery_id' => $this->id,
            'attempted_at' => now(),
            'status_code' => $statusCode,
            'error' => $error,
            'duration_ms' => $durationMs,
        ]);
    }
}
