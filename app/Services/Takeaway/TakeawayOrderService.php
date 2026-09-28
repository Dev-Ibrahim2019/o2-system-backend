<?php

namespace App\Services\Takeaway;

use App\Exceptions\ExternalApiException;
use App\Http\Resources\External\TakeawayOrderResource;
use App\Models\ExternalApiClient;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
use App\Models\TakeawayStatusEvent;
use App\Services\Integration\IntegrationOutboxWriter;
use App\Services\Integration\TakeawayDispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * طلبات التيك أواي كما يشوفها التطبيق الخارجي (تعيين السائقين، متابعة التحضير، التسليم).
 *
 * النطاق: طلبات الكول سنتر (takeaway/delivery) بعد الإغلاق F7 — tw_released_at بيتعبّى لحظة الإغلاق
 * (release) وبيتفرّغ لو الطلب انفتح من جديد (withdraw)، فالطلب بيطلع من الـAPI.
 *
 * الحالة (tw_status) منفصلة تمامًا عن orders.status: التطبيق الخارجي ما بيلمس منطق الـPOS/المطبخ/الإغلاق،
 * وما بيقدر يلغي الطلب (قرار صريح: الطلب مدفوع، والإلغاء قرار بشري بالـPOS). null = new.
 *
 *   new → accepted → preparing → ready → picked_up → delivered
 *
 * picked_up نهائي لطلب تيك أواي (العميل استلم)؛ لطلب توصيل معناه السائق استلم، وبعده delivered، وبيحتاج
 * سائق معيّن قبله. كل تغيير بيتسجّل (takeaway_status_events) وبيطلع webhook للتطبيقات التانية.
 */
class TakeawayOrderService
{
    public const STATUS_NEW = 'new';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_PREPARING = 'preparing';
    public const STATUS_READY = 'ready';
    public const STATUS_PICKED_UP = 'picked_up';
    public const STATUS_DELIVERED = 'delivered';

    public const STATUSES = [
        self::STATUS_NEW, self::STATUS_ACCEPTED, self::STATUS_PREPARING,
        self::STATUS_READY, self::STATUS_PICKED_UP, self::STATUS_DELIVERED,
    ];

    public const ORDER_TYPES = ['takeaway', 'delivery'];

    private const TRANSITIONS = [
        self::STATUS_NEW => [self::STATUS_ACCEPTED],
        self::STATUS_ACCEPTED => [self::STATUS_PREPARING],
        self::STATUS_PREPARING => [self::STATUS_READY],
        self::STATUS_READY => [self::STATUS_PICKED_UP],
        self::STATUS_PICKED_UP => [self::STATUS_DELIVERED],
        self::STATUS_DELIVERED => [],
    ];

    public function __construct(private readonly IntegrationOutboxWriter $outbox) {}

    /** كل الطلبات المتاحة للـAPI الخارجي. بدون BranchScope لأنه ما في مستخدم Laravel بهالسياق أصلًا. */
    public function query(): Builder
    {
        return Order::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('source', 'call_center')
            ->whereIn('order_type', self::ORDER_TYPES)
            ->where('status', 'closed')
            ->whereNotNull('tw_released_at');
    }

    public static function statusOf(Order $order): string
    {
        return $order->tw_status ?: self::STATUS_NEW;
    }

    /** الحالات التالية المسموحة — طلب تيك أواي (استلام من الفرع) بيخلص عند picked_up. */
    public static function allowedNext(Order $order): array
    {
        $current = self::statusOf($order);
        if ($current === self::STATUS_PICKED_UP && $order->order_type !== 'delivery') {
            return [];
        }

        return self::TRANSITIONS[$current] ?? [];
    }

    // ── دورة حياة الطلب بالـPOS: إتاحة للتيك أواي وسحبه ─────────────────────────────────────

    /** لحظة إغلاق الطلب (F7) — بيصير متاح للتيك أواي وبيطلع حدث order.created. بنفس transaction الإغلاق. */
    public function release(Order $order): void
    {
        if ($order->source !== 'call_center' || ! in_array($order->order_type, self::ORDER_TYPES, true)) {
            return;
        }

        $order->forceFill(['tw_released_at' => now()])->save();
        $this->recordEvent($order->fresh(), TakeawayDispatcher::EVENT_CREATED, null);
    }

    /** الطلب انفتح من جديد بالـPOS (reopen) — بيطلع من نطاق الـAPI وبيطلع حدث order.withdrawn. */
    public function withdraw(Order $order): void
    {
        if (! $order->tw_released_at) {
            return;
        }

        $order->forceFill(['tw_released_at' => null])->save();
        $this->recordEvent($order->fresh(), TakeawayDispatcher::EVENT_WITHDRAWN, null);
    }

    // ── تغييرات التطبيق الخارجي ─────────────────────────────────────────────────────────

    public function changeStatus(int $orderId, string $to, ExternalApiClient $client, ?string $note = null): Order
    {
        return DB::transaction(function () use ($orderId, $to, $client, $note) {
            $order = $this->lockedOrder($orderId);
            $from = self::statusOf($order);

            if ($from === $to) {
                return $order; // إعادة إرسال نفس الحالة (retry عند التطبيق الخارجي) — بدون حدث جديد
            }

            $allowed = self::allowedNext($order);
            if (! in_array($to, $allowed, true)) {
                throw new ExternalApiException(409, 'invalid_transition',
                    "Cannot change status from '{$from}' to '{$to}'.", ['current_status' => $from, 'allowed_next_statuses' => $allowed]);
            }
            if ($to === self::STATUS_PICKED_UP && $order->order_type === 'delivery' && ! $order->tw_driver_name) {
                throw new ExternalApiException(409, 'driver_required',
                    'Assign a driver (PATCH /orders/{id}/driver) before marking a delivery order as picked_up.');
            }

            $order->forceFill(['tw_status' => $to, 'tw_status_updated_at' => now()])->save();
            TakeawayStatusEvent::create([
                'order_id' => $order->id, 'external_api_client_id' => $client->id, 'event' => 'status_changed',
                'from_status' => $from, 'to_status' => $to, 'note' => $note, 'created_at' => now(),
            ]);
            $this->recordEvent($order->fresh(), TakeawayDispatcher::EVENT_STATUS_CHANGED, $client->id);

            return $order->fresh();
        }, 3);
    }

    /** تعيين/تغيير سائق لطلب توصيل — مسموح لحد ما السائق يستلم الطلب (picked_up). */
    public function assignDriver(int $orderId, array $driver, ExternalApiClient $client): Order
    {
        return DB::transaction(function () use ($orderId, $driver, $client) {
            $order = $this->lockedOrder($orderId);

            if ($order->order_type !== 'delivery') {
                throw new ExternalApiException(409, 'not_a_delivery_order', 'Drivers can only be assigned to delivery orders.');
            }
            if (in_array(self::statusOf($order), [self::STATUS_PICKED_UP, self::STATUS_DELIVERED], true)) {
                throw new ExternalApiException(409, 'driver_locked', 'The driver cannot be changed after the order was picked up.');
            }

            $order->forceFill([
                'tw_driver_name' => $driver['name'],
                'tw_driver_phone' => $driver['phone'] ?? null,
                'tw_driver_ref' => $driver['external_id'] ?? null,
                'tw_driver_assigned_at' => now(),
            ])->save();
            TakeawayStatusEvent::create([
                'order_id' => $order->id, 'external_api_client_id' => $client->id, 'event' => 'driver_assigned',
                'from_status' => self::statusOf($order), 'to_status' => self::statusOf($order),
                'driver' => array_intersect_key($driver, array_flip(['name', 'phone', 'external_id'])),
                'created_at' => now(),
            ]);
            $this->recordEvent($order->fresh(), TakeawayDispatcher::EVENT_DRIVER_ASSIGNED, $client->id);

            return $order->fresh();
        }, 3);
    }

    public function findOrFail(int $orderId): Order
    {
        $order = $this->query()->with(TakeawayOrderResource::RELATIONS)->find($orderId);
        if (! $order) {
            throw new ExternalApiException(404, 'not_found', 'Order not found.');
        }

        return $order;
    }

    private function lockedOrder(int $orderId): Order
    {
        $order = $this->query()->lockForUpdate()->find($orderId);
        if (! $order) {
            throw new ExternalApiException(404, 'not_found', 'Order not found.');
        }

        return $order;
    }

    /**
     * حدث outbox بنفس الـtransaction (Outbox pattern): الـwebhook بيطلع بالخلفية (TakeawayDispatcher) مع
     * retry. الـpayload لقطة من الطلب لحظة الحدث بنفس شكل الـAPI. source_client_id = التطبيق اللي عمل
     * التغيير، عشان ما نرجّعله الحدث تبعه.
     */
    private function recordEvent(Order $order, string $eventType, ?int $sourceClientId): void
    {
        $order->loadMissing(TakeawayOrderResource::RELATIONS);

        $this->outbox->record(
            eventType: $eventType,
            aggregateType: 'order',
            aggregateRef: $order->public_ref,
            payload: [
                'order' => (new TakeawayOrderResource($order))->resolve(),
                'source_client_id' => $sourceClientId,
            ],
        );
    }
}
