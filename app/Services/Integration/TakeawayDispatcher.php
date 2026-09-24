<?php

namespace App\Services\Integration;

use App\Models\IntegrationOutbox;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * يرسل أحداث order.closed من الـ outbox لبرنامج التيك أواي (Outbox pattern): الحدث بيتسجّل بنفس transaction
 * إغلاق الطلب، وهاد الـdispatcher بيبعته بالخلفية ويعيد المحاولة بتأخير متزايد لو فشل — فما بيضيع طلب لو
 * برنامج التيك أواي كان واقف. كل إرسال بيحمل Idempotency-Key = outbox_ref، فإعادة المحاولة (أو إعادة
 * الإرسال اليدوية) ما بتعمل طلب مكرر عندهم.
 *
 * عقد الـAPI (POST {url}/orders، Bearer token) اقتراح من عندنا — لازم يتوافق مع برنامج التيك أواي قبل التفعيل.
 * بدون TAKEAWAY_API_URL ما بيتبعت شي والأحداث بتضل pending.
 */
class TakeawayDispatcher
{
    public const EVENT = 'order.closed';

    public function isConfigured(): bool
    {
        return filled(config('call-center.takeaway.url'));
    }

    /** @return array{sent:int, failed:int, skipped:bool} */
    public function dispatchDue(int $limit = 50): array
    {
        if (! $this->isConfigured()) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => true];
        }

        $sent = 0;
        $failed = 0;
        IntegrationOutbox::query()
            ->where('event_type', self::EVENT)
            ->whereNull('published_at')
            ->where('available_at', '<=', now())
            ->where('attempt_count', '<', (int) config('call-center.takeaway.max_attempts', 8))
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (IntegrationOutbox $row) use (&$sent, &$failed) {
                $this->dispatchRow($row) ? $sent++ : $failed++;
            });

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => false];
    }

    public function dispatchRow(IntegrationOutbox $row): bool
    {
        try {
            $response = Http::withToken((string) config('call-center.takeaway.api_key'))
                ->withHeaders(['Idempotency-Key' => $row->outbox_ref])
                ->acceptJson()
                ->timeout((int) config('call-center.takeaway.timeout', 10))
                ->post(rtrim((string) config('call-center.takeaway.url'), '/').'/orders', [
                    'event_id' => $row->outbox_ref,
                    'event_type' => $row->event_type,
                    'occurred_at' => $row->occurred_at?->toIso8601String(),
                    'order' => $row->payload,
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException('HTTP '.$response->status());
            }
        } catch (\Throwable $e) {
            $attempts = (int) $row->attempt_count + 1;
            $row->update([
                'attempt_count' => $attempts,
                'last_attempt_at' => now(),
                'last_error' => Str::limit($e->getMessage(), 250, ''),
                // 30ث، 60ث، 2د، 4د ... حد أقصى ساعة
                'available_at' => now()->addSeconds(min(3600, 30 * (2 ** ($attempts - 1)))),
            ]);

            return false;
        }

        $row->update([
            'published_at' => now(),
            'attempt_count' => (int) $row->attempt_count + 1,
            'last_attempt_at' => now(),
            'last_error' => null,
        ]);

        return true;
    }

    /**
     * حالة إرسال كل طلب مغلق للتيك أواي: sent | failed | pending. مفتاحها public_ref للطلب.
     *
     * @param  array<int,string>  $publicRefs
     * @return array<string,string>
     */
    public function statusByOrderRef(array $publicRefs): array
    {
        if ($publicRefs === []) {
            return [];
        }

        return IntegrationOutbox::query()
            ->where('event_type', self::EVENT)
            ->whereIn('aggregate_ref', $publicRefs)
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (IntegrationOutbox $row) => [
                $row->aggregate_ref => match (true) {
                    $row->published_at !== null => 'sent',
                    $row->last_error !== null => 'failed',
                    default => 'pending',
                },
            ])
            ->all();
    }

    /** إعادة إرسال يدوية: بتصفّر العدّاد وبتحاول فورًا. null = ما في حدث إغلاق لهالطلب. */
    public function resend(string $publicRef): ?bool
    {
        $row = IntegrationOutbox::query()->where('event_type', self::EVENT)->where('aggregate_ref', $publicRef)->latest('id')->first();
        if (! $row) {
            return null;
        }

        $row->update(['published_at' => null, 'attempt_count' => 0, 'last_error' => null, 'available_at' => now()]);

        return $this->isConfigured() ? $this->dispatchRow($row->fresh()) : false;
    }
}
