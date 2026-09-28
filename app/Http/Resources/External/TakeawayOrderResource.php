<?php

namespace App\Http\Resources\External;

use App\Models\Order;
use App\Services\CallCenter\OrderFlowService;
use App\Services\Takeaway\TakeawayOrderService;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * شكل الطلب للتطبيق الخارجي — عقد ثابت وموثّق (docs/external-takeaway-api.yaml). قائمة بيضاء صريحة: ما
 * بيطلع أي حقل داخلي (تكلفة، موظفين/كاشير، خصومات المحرك بالتفصيل، أرصدة، أرقام داخلية للخانات...).
 * نفس الـresource بيُستخدم لردود الـAPI ولـpayload الـwebhooks، فالشكل واحد بالمكانين.
 *
 * التواريخ ISO 8601 بتوقيت UTC (2027-02-09T12:30:00Z). المبالغ أرقام بمنزلتين عشريتين.
 */
class TakeawayOrderResource extends JsonResource
{
    /** العلاقات اللي لازم تنحمّل قبل الـserialize (تجنّب N+1 بالقوائم) */
    public const RELATIONS = ['items', 'branch:id,name', 'invoice.payments'];

    private const ADDRESS_FIELDS = [
        'address', 'label', 'city', 'area', 'district', 'street', 'landmark', 'building_no', 'floor', 'apartment', 'delivery_notes',
    ];

    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;
        $isDelivery = $order->order_type === 'delivery';

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'order_type' => $order->order_type,
            'status' => TakeawayOrderService::statusOf($order),
            'allowed_next_statuses' => TakeawayOrderService::allowedNext($order),
            'branch' => $order->branch ? ['id' => $order->branch->id, 'name' => $order->branch->name] : null,
            'customer' => [
                'name' => $order->customer_name,
                'phone' => $order->customer_phone,
            ],
            'delivery' => $isDelivery ? [
                'address' => $this->address($order->delivery_address_snapshot),
                'notes' => $order->delivery_notes,
            ] : null,
            'driver' => $order->tw_driver_name ? [
                'name' => $order->tw_driver_name,
                'phone' => $order->tw_driver_phone,
                'external_id' => $order->tw_driver_ref,
                'assigned_at' => self::iso($order->tw_driver_assigned_at),
            ] : null,
            'items' => $order->items
                ->where('status', '!=', 'cancelled')
                ->map(fn ($item) => [
                    'name' => $item->item_name_ar ?: $item->item_name,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => self::money($item->price),
                    'total' => self::money($item->total),
                    // النظام ما فيه إضافات (modifiers) حاليًا — الحقل ثابت بالعقد عشان ما يتغيّر الشكل لما تنضاف
                    'modifiers' => [],
                    'notes' => $item->notes,
                ])
                ->values()
                ->all(),
            'subtotal' => self::money($order->subtotal),
            'discount' => self::money((float) $order->discount_amount + (float) $order->engine_discount_amount),
            'tax' => self::money($order->tax_amount),
            'delivery_fee' => self::money($order->delivery_fee),
            'total' => self::money($order->total),
            'payment_status' => OrderFlowService::paymentState($order),
            'payment_method' => $this->paymentMethod($order),
            'pickup_time' => self::iso($order->scheduled_at),
            'notes' => $order->note,
            'released_at' => self::iso($order->tw_released_at),
            'status_updated_at' => self::iso($order->tw_status_updated_at),
            'created_at' => self::iso($order->created_at),
            'updated_at' => self::iso($order->updated_at),
        ];
    }

    public static function iso(?CarbonInterface $date): ?string
    {
        return $date?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private static function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    /** طريقة الدفع من دفعات الفاتورة: cash | card | bank | wallet | account، أو mixed لأكتر من طريقة. */
    private function paymentMethod(Order $order): ?string
    {
        $methods = collect($order->invoice?->payments ?? [])->pluck('method')->filter()->unique()->values();

        return match ($methods->count()) {
            0 => null,
            1 => (string) $methods->first(),
            default => 'mixed',
        };
    }

    /** العنوان من لقطة الطلب — الحقول المعروفة بس (بدون عرض التوصيل الداخلي/المنطقة/الرسوم المحسوبة). */
    private function address(?array $snapshot): ?array
    {
        if (! $snapshot) {
            return null;
        }
        $address = array_filter(
            array_intersect_key($snapshot, array_flip(self::ADDRESS_FIELDS)),
            fn ($v) => is_scalar($v) && $v !== '',
        );

        return $address === [] ? null : $address;
    }
}
