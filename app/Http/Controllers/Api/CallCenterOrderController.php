<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\StoreCallCenterOrderRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductionTicket;
use App\Services\CallCenter\CallCenterOrderCreationService;
use App\Services\CallCenter\CallCenterOrderExecutionService;
use App\Services\CallCenter\DepartmentTicketPrinter;
use App\Services\CallCenter\OrderAmendmentService;
use App\Services\CallCenter\OrderClosureService;
use App\Services\CallCenter\OrderFlowService;
use App\Services\Integration\TakeawayDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CallCenterOrderController extends ApiController
{
    /**
     * POST /api/call-center/orders/{order}/close — إغلاق (F7). مسموح فقط لو الطلب مدفوع ومنفّذ،
     * والفحص بالباك اند (OrderClosureService) مش بس بالواجهة. الرد 422 بيوضّح شو الناقص.
     */
    public function close(Request $request, Order $order, OrderClosureService $closure): JsonResponse
    {
        if (! $this->agentCan('call-center.change-order-status')) {
            return $this->error('لا تملك صلاحية تغيير حالة الطلب.', 403);
        }

        $closed = $closure->close($order, $request->user());

        return $this->success('تم إغلاق الطلب', ['id' => $closed->id, 'status' => $closed->status] + OrderFlowService::describe($closed));
    }

    /** POST /api/call-center/orders/{order}/takeaway-resend — إعادة إرسال طلب مغلق للتيك أواي (بعد فشل الإرسال). */
    public function resendToTakeaway(Order $order, TakeawayDispatcher $dispatcher): JsonResponse
    {
        if (! $this->agentCan('call-center.change-order-status')) {
            return $this->error('لا تملك صلاحية تغيير حالة الطلب.', 403);
        }

        $sent = $dispatcher->resend((string) $order->public_ref);
        if ($sent === null) {
            return $this->error('ما في حدث إغلاق مسجّل لهذا الطلب.', 422);
        }

        return $this->success($sent ? 'تم إرسال الطلب للتيك أواي.' : 'تعذر الإرسال، سيعاد المحاولة تلقائيًا.', [
            'takeaway_sync' => $dispatcher->statusByOrderRef([(string) $order->public_ref])[$order->public_ref] ?? null,
        ]);
    }

    /**
     * DELETE /api/call-center/orders/{order}/items/{orderItem} — إزالة صنف. قبل التنفيذ مباشرة؛ بعده سبب
     * إلزامي + تذكرة إلغاء للقسم؛ بعد الدفع مشرف فقط (راجع OrderAmendmentService).
     */
    public function removeItem(Request $request, Order $order, OrderItem $orderItem, OrderAmendmentService $amendments): JsonResponse
    {
        if (! $this->agentCan('call-center.create-order')) {
            return $this->error('لا تملك صلاحية تعديل الطلبات.', 403);
        }
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $result = $amendments->removeItem($order, $orderItem, $request->user(), $data['reason'] ?? null);

        return $this->success('تمت إزالة الصنف', $this->amendmentPayload($order->fresh(), $result));
    }

    /**
     * PUT /api/call-center/orders/{order} — تعديل الطلب (نفس الرقم ونفس الخانة). الأصناف بشكلها النهائي؛
     * الفرق بس هو اللي بينطبّق ويطلع للأقسام. الحفظ محكوم بقفل التعديل المتزامن.
     */
    public function update(Request $request, Order $order, OrderAmendmentService $amendments): JsonResponse
    {
        if (! $this->agentCan('call-center.create-order')) {
            return $this->error('لا تملك صلاحية تعديل الطلبات.', 403);
        }
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $amendments->edit($order, $request->user(), $data, $data['reason'] ?? null);

        return $this->success($result['changed'] ? 'تم تعديل الطلب' : 'لا يوجد تغيير', $this->amendmentPayload($order->fresh(), $result));
    }

    /** POST /api/call-center/orders/{order}/edit-lock — حجز/تجديد قفل التعديل (heartbeat كل ~45 ثانية). 409 باسم من يعدّل. */
    public function acquireEditLock(Request $request, Order $order, OrderAmendmentService $amendments): JsonResponse
    {
        if (! $this->agentCan('call-center.create-order')) {
            return $this->error('لا تملك صلاحية تعديل الطلبات.', 403);
        }

        return $this->success('تم حجز الطلب للتعديل', $amendments->acquireLock($order, $request->user()));
    }

    public function releaseEditLock(Request $request, Order $order, OrderAmendmentService $amendments): JsonResponse
    {
        $amendments->releaseLock($order, $request->user());

        return $this->success('تم تحرير قفل التعديل', null);
    }

    private function amendmentPayload(Order $order, array $result): array
    {
        return $this->flowPayload($order) + [
            'total' => (float) $order->total,
            'warnings' => $result['warnings'] ?? [],
            'change_tickets' => $result['change_tickets'] ?? [],
        ];
    }

    /** GET /api/call-center/orders/{order}/flow — حالة التدفق + تذاكر الأقسام وحالة طباعة كل قسم (للـDrawer). */
    public function flow(Order $order): JsonResponse
    {
        if (! $this->agentCan('call-center.view-active-orders')) {
            return $this->error('لا تملك صلاحية عرض الطلبات النشطة.', 403);
        }

        return $this->success('تم تحميل حالة الطلب', $this->flowPayload($order));
    }

    /**
     * POST /api/call-center/orders/{order}/execute — تنفيذ فوري (إرسال للأقسام + طباعة). بيشتغل لطلب مدفوع:
     * مجدول قبل موعده (بيلغي الجدولة)، أو فشل إرساله قبل (إعادة محاولة). Idempotent.
     */
    public function execute(Request $request, Order $order, CallCenterOrderExecutionService $execution): JsonResponse
    {
        if (! $this->agentCan('call-center.change-order-status')) {
            return $this->error('لا تملك صلاحية تغيير حالة الطلب.', 403);
        }

        $result = $execution->executeNow($order, (int) $request->user()->id);
        $message = $result->kitchen_release_status === Order::KITCHEN_RELEASE_STATUS_RELEASED
            ? 'تم تنفيذ الطلب وإرساله للأقسام.'
            : 'تعذر تنفيذ الطلب، حاول مرة ثانية.';

        return $this->success($message, $this->flowPayload($result));
    }

    /** PUT /api/call-center/orders/{order}/schedule — جدولة التنفيذ على السيرفر بوقت محدد (أو تعديل الوقت). */
    public function schedule(Request $request, Order $order, CallCenterOrderExecutionService $execution): JsonResponse
    {
        if (! $this->agentCan('call-center.change-order-status')) {
            return $this->error('لا تملك صلاحية تغيير حالة الطلب.', 403);
        }
        $data = $request->validate(['scheduled_at' => ['required', 'date']]);

        return $this->success('تمت جدولة التنفيذ', $this->flowPayload(
            $execution->schedule($order, \Illuminate\Support\Carbon::parse($data['scheduled_at']), (int) $request->user()->id),
        ));
    }

    /** DELETE /api/call-center/orders/{order}/schedule — إلغاء الجدولة (الطلب بيرجع بانتظار التنفيذ اليدوي). */
    public function unschedule(Request $request, Order $order, CallCenterOrderExecutionService $execution): JsonResponse
    {
        if (! $this->agentCan('call-center.change-order-status')) {
            return $this->error('لا تملك صلاحية تغيير حالة الطلب.', 403);
        }

        return $this->success('تم إلغاء الجدولة', $this->flowPayload($execution->unschedule($order, (int) $request->user()->id)));
    }

    /** POST /api/call-center/orders/{order}/tickets/{ticket}/reprint — إعادة طباعة تذكرة قسم فشلت طابعته. */
    public function reprintTicket(Order $order, ProductionTicket $ticket, DepartmentTicketPrinter $printer): JsonResponse
    {
        if (! $this->agentCan('call-center.change-order-status')) {
            return $this->error('لا تملك صلاحية تغيير حالة الطلب.', 403);
        }
        if ((int) $ticket->order_id !== (int) $order->id) {
            return $this->error('التذكرة لا تنتمي لهذا الطلب.', 422);
        }

        $result = $printer->printTicket($order, $ticket);

        return $this->success($result['success'] ? 'تمت إعادة الطباعة.' : 'فشلت إعادة الطباعة.', $this->flowPayload($order->fresh()));
    }

    private function flowPayload(Order $order): array
    {
        $order->loadMissing('tickets.department');

        return [
            'id' => $order->id,
            'status' => $order->status,
            'scheduled_at' => $order->scheduled_at,
            'executed_at' => $order->executed_at,
            'execution_failed_reason' => $order->execution_failed_reason,
            'tickets' => $order->tickets->map(fn (ProductionTicket $t) => [
                'id' => $t->id,
                'type' => $t->type ?? ProductionTicket::TYPE_ORDER,
                'ticket_number' => $t->ticket_number,
                'department' => $t->department?->name,
                'print_status' => $t->print_status,
                'print_error' => $t->print_error,
                'printed_at' => $t->printed_at,
            ])->values(),
        ] + OrderFlowService::describe($order);
    }

    public function store(StoreCallCenterOrderRequest $request, CallCenterOrderCreationService $service): JsonResponse
    {
        // فحص صلاحية create-order موجود بـ StoreCallCenterOrderRequest::authorize() — قبل هذه
        // النقطة بمرحلة Laravel، لأن جسم الكنترولر لا يُنفَّذ إطلاقاً لو فشل الفحص هناك.
        try {
            return $this->success(
                'تم حفظ طلب الكول سنتر',
                $service->create($request->validated(), $request->user()),
                201,
            );
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $requestId = (string) Str::uuid();
            Log::error('Call-center order transaction failed', [
                'request_id' => $requestId,
                'user_id' => $request->user()?->id,
                'ticket_id' => $request->integer('call_ticket_id'),
                'exception' => $exception,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'تعذر حفظ بيانات العميل والطلب. لم يتم إنشاء فاتورة.',
                'request_id' => $requestId,
            ], 500);
        }
    }
}
