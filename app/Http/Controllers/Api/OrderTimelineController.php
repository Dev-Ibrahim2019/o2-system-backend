<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class OrderTimelineController extends ApiController
{
    /**
     * سجل زمني كامل للطلب (Audit Trail / Timeline)
     *
     * يُرجع:
     * - من فتح الطلب ومتى (opened_by, created_at)
     * - من أضاف كل صنف (created_by على order_items)
     * - من طبع الفاتورة ومتى (printed_by, printed_at)
     * - من أغلق الطلب ومتى (closed_by, closed_at على الفاتورة)
     */
    public function timeline(Order $order): JsonResponse
    {
        $order->load([
            'opener', 'closer', 'printer', 'items.creator', 'invoice.closedByUser',
            // Feedback is an action taken on the order — it belongs in this trail.
            'feedback.recorder', 'items.feedback.recorder',
        ]);

        $events = [];

        // 1. فتح الطلب
        $events[] = [
            'type' => 'order_opened',
            'label' => 'تم فتح الطلب',
            'user' => $order->opener ? [
                'id' => $order->opener->id,
                'name' => $order->opener->name,
            ] : null,
            'timestamp' => $order->created_at->toISOString(),
            'details' => [
                'order_number' => $order->order_number,
                'order_type' => $order->order_type,
                'table_number' => $order->table_number,
            ],
        ];

        // 2. إضافة الأصناف (مجمعة حسب المستخدم والوقت)
        $itemsByCreator = $order->items
            ->filter(fn($item) => $item->created_by)
            ->groupBy('created_by');

        foreach ($itemsByCreator as $userId => $items) {
            $creator = $items->first()->creator;
            $events[] = [
                'type' => 'items_added',
                'label' => 'تم إضافة ' . count($items) . ' صنف',
                'user' => $creator ? [
                    'id' => $creator->id,
                    'name' => $creator->name,
                ] : null,
                'timestamp' => $order->created_at->toISOString(), // يمكن تحسينه إذا كان هناك created_at على order_items
                'details' => [
                    'items_count' => count($items),
                    'items' => $items->map(fn($item) => [
                        'id' => $item->id,
                        'name' => $item->item_name_ar ?? $item->item_name,
                        'quantity' => $item->quantity,
                        'price' => $item->price,
                    ])->toArray(),
                ],
            ];
        }

        // 3. طباعة الفاتورة
        if ($order->printed_by) {
            $events[] = [
                'type' => 'invoice_printed',
                'label' => 'تم طباعة الفاتورة',
                'user' => $order->printer ? [
                    'id' => $order->printer->id,
                    'name' => $order->printer->name,
                ] : null,
                'timestamp' => $order->printed_at?->toISOString(),
                'details' => [],
            ];
        }

        // 4. إغلاق الطلب (الدفع)
        if ($order->closed_by) {
            $invoice = $order->invoice;
            $events[] = [
                'type' => 'order_closed',
                'label' => 'تم إغلاق الطلب (الدفع)',
                'user' => $order->closer ? [
                    'id' => $order->closer->id,
                    'name' => $order->closer->name,
                ] : null,
                'timestamp' => $invoice?->closed_at?->toISOString() ?? $order->updated_at->toISOString(),
                'details' => [
                    'total' => $order->total,
                    'payment_method' => $invoice?->payment_method,
                ],
            ];
        }

        // 5. تقييم الأصناف (سطر لكل صنف مُقيَّم — من قيّمه ومتى)
        foreach ($order->items as $item) {
            $fb = $item->feedback;
            if (! $fb) {
                continue;
            }
            $name = $item->item_name_ar ?: $item->item_name;
            $events[] = [
                'type' => 'item_rated',
                'label' => "تقييم صنف «{$name}» — {$fb->rating}/5",
                'user' => $fb->recorder ? [
                    'id' => $fb->recorder->id,
                    'name' => $fb->recorder->name,
                ] : null,
                'timestamp' => ($fb->updated_at ?? $fb->created_at)?->toISOString(),
                'details' => [
                    'item_id' => $item->id,
                    'item_name' => $name,
                    'rating' => $fb->rating,
                    'notes' => $fb->notes,
                ],
            ];
        }

        // 6. تقييم الطلب العام (طعام / خدمة / سرعة توصيل)
        if ($order->feedback) {
            $fb = $order->feedback;
            $parts = ["طعام {$fb->food_quality}/5", "خدمة {$fb->service_quality}/5"];
            if ($fb->delivery_speed) {
                $parts[] = "توصيل {$fb->delivery_speed}/5";
            }
            $events[] = [
                'type' => 'order_rated',
                'label' => 'تقييم الطلب — ' . implode(' · ', $parts),
                'user' => $fb->recorder ? [
                    'id' => $fb->recorder->id,
                    'name' => $fb->recorder->name,
                ] : null,
                'timestamp' => ($fb->updated_at ?? $fb->created_at)?->toISOString(),
                'details' => [
                    'food_quality' => $fb->food_quality,
                    'service_quality' => $fb->service_quality,
                    'delivery_speed' => $fb->delivery_speed,
                    'notes' => $fb->notes,
                ],
            ];
        }

        // ترتيب الأحداث حسب الوقت
        usort($events, fn($a, $b) => strtotime($a['timestamp'] ?? 'now') - strtotime($b['timestamp'] ?? 'now'));

        return $this->success('سجل الطلب الزمني', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'events' => $events,
        ]);
    }
}
