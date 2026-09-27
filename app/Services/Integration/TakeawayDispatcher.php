<?php

namespace App\Services\Integration;

use App\Models\ExternalApiClient;
use App\Models\ExternalWebhookDelivery;
use App\Models\IntegrationOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Webhooks التيك أواي (Outbox pattern): أحداث takeaway.* بتتسجّل بالـoutbox بنفس transaction التغيير
 * (إغلاق F7، تغيير حالة، تعيين سائق، سحب طلب)، وهاد الـdispatcher بالخلفية (takeaway:dispatch):
 *
 *   1) fan-out: كل حدث جديد بينعمله delivery لكل تطبيق خارجي فعّال عنده webhook_url — ما عدا التطبيق اللي
 *      عمل التغيير نفسه (source_client_id)، هو عارف.
 *   2) إرسال الـdeliveries المستحقة: POST موقّع بـ HMAC-SHA256 (X-Signature)، وإعادة محاولة بتأخير متزايد
 *      لحد max_attempts، وكل محاولة بتتسجّل (external_webhook_attempts).
 *
 * التوقيع: X-Signature = "sha256=" . hex(HMAC_SHA256(secret, "{X-Timestamp}.{raw body}")). التطبيق بيرفض أي
 * timestamp أقدم من 5 دقائق (replay). Idempotency-Key = X-Event-Id = outbox_ref، ثابت بين المحاولات.
 */
class TakeawayDispatcher
{
    public const EVENT_CREATED = 'takeaway.order_created';
    public const EVENT_STATUS_CHANGED = 'takeaway.order_status_changed';
    public const EVENT_DRIVER_ASSIGNED = 'takeaway.order_driver_assigned';
    public const EVENT_WITHDRAWN = 'takeaway.order_withdrawn';

    public const EVENTS = [self::EVENT_CREATED, self::EVENT_STATUS_CHANGED, self::EVENT_DRIVER_ASSIGNED, self::EVENT_WITHDRAWN];

    /** اسم الحدث كما بيوصل للتطبيق الخارجي (X-Event / type) */
    public const PUBLIC_NAMES = [
        self::EVENT_CREATED => 'order.created',
        self::EVENT_STATUS_CHANGED => 'order.status_changed',
        self::EVENT_DRIVER_ASSIGNED => 'order.driver_assigned',
        self::EVENT_WITHDRAWN => 'order.withdrawn',
    ];

    /** @deprecated الاسم القديم لحدث الإغلاق — نفس EVENT_CREATED */
    public const EVENT = self::EVENT_CREATED;

    public function isConfigured(): bool
    {
        return $this->webhookClients()->exists();
    }

    /** @return array{fanned_out:int, sent:int, failed:int, skipped:bool} */
    public function dispatchDue(int $limit = 100): array
    {
        $fannedOut = $this->fanOut();
        $sent = 0;
        $failed = 0;

        ExternalWebhookDelivery::query()
            ->with(['client', 'outbox'])
            ->whereIn('status', [ExternalWebhookDelivery::STATUS_PENDING, ExternalWebhookDelivery::STATUS_FAILED])
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (ExternalWebhookDelivery $delivery) use (&$sent, &$failed) {
                $this->deliver($delivery) ? $sent++ : $failed++;
            });

        return ['fanned_out' => $fannedOut, 'sent' => $sent, 'failed' => $failed, 'skipped' => ! $this->isConfigured()];
    }

    /** بيحوّل أحداث الـoutbox الجديدة لـdeliveries لكل تطبيق عنده webhook. بيرجّع عدد الأحداث. */
    public function fanOut(int $limit = 200): int
    {
        $events = IntegrationOutbox::query()
            ->whereIn('event_type', self::EVENTS)
            ->whereNull('published_at')
            ->where('available_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($events->isEmpty()) {
            return 0;
        }

        $clients = $this->webhookClients()->get(['id']);
        foreach ($events as $event) {
            DB::transaction(function () use ($event, $clients) {
                $source = $event->payload['source_client_id'] ?? null;
                $rows = $clients
                    ->reject(fn (ExternalApiClient $c) => $source !== null && (int) $c->id === (int) $source)
                    ->map(fn (ExternalApiClient $c) => [
                        'external_api_client_id' => $c->id,
                        'integration_outbox_id' => $event->id,
                        'event_type' => $event->event_type,
                        'aggregate_ref' => $event->aggregate_ref,
                        'status' => ExternalWebhookDelivery::STATUS_PENDING,
                        'attempt_count' => 0,
                        'next_attempt_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->values()->all();

                if ($rows !== []) {
                    ExternalWebhookDelivery::query()->insertOrIgnore($rows);
                }
                $event->update(['published_at' => now()]);
            });
        }

        return $events->count();
    }

    public function deliver(ExternalWebhookDelivery $delivery): bool
    {
        $client = $delivery->client;
        $event = $delivery->outbox;
        if (! $client || ! $event || $client->isRevoked() || ! $client->webhook_url) {
            $delivery->update(['status' => ExternalWebhookDelivery::STATUS_DEAD, 'last_error' => 'client revoked or webhook removed']);

            return false;
        }

        $body = json_encode([
            'id' => $event->outbox_ref,
            'type' => self::PUBLIC_NAMES[$event->event_type] ?? $event->event_type,
            'occurred_at' => $event->occurred_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'data' => $event->payload['order'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;

        $started = microtime(true);
        $status = null;
        $error = null;
        try {
            $response = Http::withBody($body, 'application/json')
                ->withHeaders([
                    'Accept' => 'application/json',
                    'X-Event' => self::PUBLIC_NAMES[$event->event_type] ?? $event->event_type,
                    'X-Event-Id' => $event->outbox_ref,
                    'Idempotency-Key' => $event->outbox_ref,
                    'X-Timestamp' => $timestamp,
                    'X-Signature' => self::sign((string) $client->webhook_secret, $timestamp, $body),
                ])
                ->timeout((int) config('call-center.takeaway.timeout', 10))
                ->post($client->webhook_url);
            $status = $response->status();
            if (! $response->successful()) {
                $error = 'HTTP '.$status;
            }
        } catch (\Throwable $e) {
            $error = Str::limit($e->getMessage(), 250, '');
        }
        $duration = (int) round((microtime(true) - $started) * 1000);

        $attempts = $delivery->attempt_count + 1;
        $delivery->logAttempt($status, $error, $duration);

        if ($error === null) {
            $delivery->update([
                'status' => ExternalWebhookDelivery::STATUS_SENT, 'attempt_count' => $attempts, 'delivered_at' => now(),
                'last_status_code' => $status, 'last_error' => null, 'next_attempt_at' => null,
            ]);

            return true;
        }

        $dead = $attempts >= (int) config('call-center.takeaway.max_attempts', 8);
        $delivery->update([
            'status' => $dead ? ExternalWebhookDelivery::STATUS_DEAD : ExternalWebhookDelivery::STATUS_FAILED,
            'attempt_count' => $attempts,
            'last_status_code' => $status,
            'last_error' => $error,
            // 30ث، 60ث، 2د، 4د ... حد أقصى ساعة
            'next_attempt_at' => $dead ? null : now()->addSeconds(min(3600, 30 * (2 ** ($attempts - 1)))),
        ]);

        return false;
    }

    public static function sign(string $secret, string $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * حالة إرسال الطلب المغلق للتيك أواي (لشاشة الطلبات المغلقة): sent | failed | pending، مفتاحها public_ref.
     * مبنية على حدث order.created: sent لو وصل لتطبيق واحد على الأقل.
     *
     * @param  array<int,string>  $publicRefs
     * @return array<string,string>
     */
    public function statusByOrderRef(array $publicRefs): array
    {
        if ($publicRefs === []) {
            return [];
        }

        $result = IntegrationOutbox::query()
            ->where('event_type', self::EVENT_CREATED)
            ->whereIn('aggregate_ref', $publicRefs)
            ->pluck('aggregate_ref')
            ->mapWithKeys(fn ($ref) => [$ref => 'pending'])
            ->all();

        ExternalWebhookDelivery::query()
            ->where('event_type', self::EVENT_CREATED)
            ->whereIn('aggregate_ref', $publicRefs)
            ->get(['aggregate_ref', 'status'])
            ->groupBy('aggregate_ref')
            ->each(function ($rows, $ref) use (&$result) {
                $statuses = $rows->pluck('status');
                $result[$ref] = match (true) {
                    $statuses->contains(ExternalWebhookDelivery::STATUS_SENT) => 'sent',
                    $statuses->contains(ExternalWebhookDelivery::STATUS_FAILED), $statuses->contains(ExternalWebhookDelivery::STATUS_DEAD) => 'failed',
                    default => 'pending',
                };
            });

        return $result;
    }

    /**
     * إعادة إرسال يدوية لحدث order.created لطلب (زر "إعادة" بشاشة الطلبات المغلقة): بتصفّر المحاولات وبتبعت فورًا.
     * null = ما في حدث لهالطلب. true = وصل لكل التطبيقات.
     */
    public function resend(string $publicRef): ?bool
    {
        $event = IntegrationOutbox::query()->where('event_type', self::EVENT_CREATED)->where('aggregate_ref', $publicRef)->latest('id')->first();
        if (! $event) {
            return null;
        }
        if ($event->published_at === null) {
            $this->fanOut();
        }

        $deliveries = ExternalWebhookDelivery::query()->with(['client', 'outbox'])->where('integration_outbox_id', $event->id)->get();
        if ($deliveries->isEmpty()) {
            return false;
        }

        $allSent = true;
        foreach ($deliveries as $delivery) {
            if ($delivery->status === ExternalWebhookDelivery::STATUS_SENT) {
                continue;
            }
            $delivery->update(['status' => ExternalWebhookDelivery::STATUS_PENDING, 'attempt_count' => 0, 'next_attempt_at' => now()]);
            $allSent = $this->deliver($delivery->fresh(['client', 'outbox'])) && $allSent;
        }

        return $allSent;
    }

    private function webhookClients()
    {
        return ExternalApiClient::query()->whereNull('revoked_at')->whereNotNull('webhook_url');
    }
}
