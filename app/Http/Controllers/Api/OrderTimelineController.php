<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\Order;
use App\Models\OrderActivityLog;
use Illuminate\Http\JsonResponse;

class OrderTimelineController extends ApiController
{
    /**
     * سجل زمني كامل للطلب (Audit Trail / Timeline)
     *
     * يُرجع:
     * - من فتح الطلب ومتى (opened_by, created_at)
     * - من أضاف كل صنف ومتى (created_by/created_at على order_items — مجمّعة
     *   حسب من أضافها ومتى بالضبط، وليس وقت فتح الطلب الأصلي، حتى تظهر
     *   إضافة صنف لطلب محفوظ مسبقًا كحدث منفصل وواضح)
     * - من طبع الفاتورة ومتى (printed_by, printed_at)
     * - من أغلق الطلب ومتى (closed_by, closed_at على الفاتورة)
     * - كل حدث مُسجَّل بـ order_activity_log (OrderStatusService — تأكيد،
     *   إغلاق صريح/تلقائي، إعادة فتح، تعيين/إلغاء سائق، دفعة) — كانت هذه
     *   الأحداث تُسجَّل فعليًا لكن لا تظهر هنا إطلاقًا لأن هذا الكنترولر
     *   لم يكن يقرأ من هذا الجدول أصلاً.
     */
    public function timeline(Order $order): JsonResponse
    {
        $order->load([
            'opener', 'closer', 'printer', 'items.creator', 'invoice.closedByUser',
            // Feedback is an action taken on the order — it belongs in this trail.
            'feedback.recorder', 'items.feedback.recorder',
            'items.feedback.complaint:id,created_by,created_at', 'items.feedback.complaint.createdBy:id,name',
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

        // 2. إضافة الأصناف — مجمعة حسب (المستخدم + دقيقة الإضافة الفعلية)،
        // وليس حسب المستخدم وحده: صنفان أضافهما نفس الموظف بفارق ساعات
        // (مثلاً عند فتح الطلب، ثم لاحقًا بعد تأكيده) يظهران كحدثين
        // منفصلين بدل أن يُدمَجا في حدث واحد بتوقيت فتح الطلب الأصلي.
        $itemsByCreatorAndMinute = $order->items
            ->filter(fn($item) => $item->created_by)
            ->groupBy(fn($item) => $item->created_by . '|' . ($item->created_at?->format('Y-m-d H:i') ?? $order->created_at->format('Y-m-d H:i')));

        foreach ($itemsByCreatorAndMinute as $items) {
            $creator = $items->first()->creator;
            $timestamp = $items->first()->created_at ?? $order->created_at;
            $events[] = [
                'type' => 'items_added',
                'label' => 'تم إضافة ' . count($items) . ' صنف',
                'user' => $creator ? [
                    'id' => $creator->id,
                    'name' => $creator->name,
                ] : null,
                'timestamp' => $timestamp->toISOString(),
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

            // 5b. تصعيد التقييم إلى شكوى
            if ($fb->complaint) {
                $events[] = [
                    'type' => 'item_complaint',
                    'label' => "تسجيل شكوى على صنف «{$name}»",
                    'user' => $fb->complaint->createdBy
                        ? ['id' => $fb->complaint->createdBy->id, 'name' => $fb->complaint->createdBy->name]
                        : null,
                    'timestamp' => $fb->complaint->created_at?->toISOString(),
                    'details' => [
                        'item_id' => $item->id,
                        'item_name' => $name,
                        'complaint_id' => $fb->complaint->id,
                    ],
                ];
            }
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

        // 7. كل حدث سجّلته OrderStatusService فعليًا بـorder_activity_log —
        // تأكيد الإرسال للأقسام، الإغلاق (التلقائي أو الصريح)، إعادة الفتح،
        // تعيين/إلغاء تعيين سائق، تسجيل دفعة. كانت تُكتب فعليًا لكن لا تظهر
        // هنا إطلاقًا لأن هذا الكنترولر لم يقرأ من هذا الجدول من الأساس —
        // فمثلاً إغلاق طلب كول سنتر (status='closed' عبر maybeAutoClose())
        // كان يختفي كليًا من سجل النشاطات رغم تسجيله بالفعل.
        $actionLabels = [
            'status_change' => 'تغيير الحالة',
            'closed' => 'تم إغلاق الطلب',
            'reopened' => 'تمت إعادة فتح الطلب',
            'force_completed' => 'إنهاء الطلب يدويًا',
            'preparation_started' => 'بدء التجهيز',
            'item_added' => 'إضافة صنف',
            'items_updated' => 'تعديل أصناف الطلب',
            'item_removed' => 'حذف صنف',
            'payment' => 'تسجيل دفعة',
            'driver_assigned' => 'تعيين سائق',
            'driver_unassigned' => 'إلغاء تعيين السائق',
        ];

        $activityLogs = OrderActivityLog::where('order_id', $order->id)
            ->with('actor:id,name')
            ->get();

        foreach ($activityLogs as $log) {
            $label = $actionLabels[$log->action_type] ?? $log->action_type;
            if ($log->from_status && $log->to_status) {
                $label .= " ({$log->from_status} ← {$log->to_status})";
            }

            $events[] = [
                'type' => 'activity_log_' . $log->action_type,
                'label' => $label,
                'user' => $log->actor ? [
                    'id' => $log->actor->id,
                    'name' => $log->actor->name,
                ] : null,
                'timestamp' => $log->created_at?->toISOString(),
                'details' => [
                    'action_type' => $log->action_type,
                    'from_status' => $log->from_status,
                    'to_status' => $log->to_status,
                    'note' => $log->note,
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
