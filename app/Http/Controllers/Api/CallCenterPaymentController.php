<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\ConfirmCallCenterTransferRequest;
use App\Http\Requests\Api\DebitCallCenterEntityRequest;
use App\Http\Resources\CallCenterOrderExecutionResource;
use App\Models\Order;
use App\Services\CallCenter\CallCenterOrderExecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class CallCenterPaymentController extends ApiController
{
    public function confirmTransfer(ConfirmCallCenterTransferRequest $request, Order $order, CallCenterOrderExecutionService $service): JsonResponse
    {
        Gate::authorize('execute-call-center-payment', $order);
        $data = $request->validated();
        $result = $service->confirmBankTransferAndRelease(
            $order, $data['reference_number'], $data['payment_method_id'], (float) $data['amount'],
            $data['idempotency_key'], (int) $request->user()->id,
        );

        return $this->success($this->executionMessage($result), new CallCenterOrderExecutionResource($result));
    }

    public function debitEntity(DebitCallCenterEntityRequest $request, Order $order, CallCenterOrderExecutionService $service): JsonResponse
    {
        Gate::authorize('execute-call-center-payment', $order);
        $data = $request->validated();
        $result = $service->debitEntityAccountAndRelease(
            $order, $data['entity_type'], (int) $data['entity_id'], (float) $data['amount'],
            $data['idempotency_key'], (int) $request->user()->id,
        );

        return $this->success($this->executionMessage($result), new CallCenterOrderExecutionResource($result));
    }

    private function executionMessage(Order $order): string
    {
        if ($order->payment_status !== Order::PAYMENT_STATUS_PAID) {
            return 'تم تسجيل الدفعة الجزئية، وما زال الطلب بانتظار استكمال الدفع.';
        }

        return $order->kitchen_release_status === Order::KITCHEN_RELEASE_STATUS_RELEASED
            ? 'تم اكتمال الدفع وإرسال الطلب للمطبخ.'
            : 'تم اكتمال الدفع، لكن تعذر إرسال الطلب للمطبخ.';
    }
}
