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
use Illuminate\Support\Facades\Storage;

class CallCenterPaymentController extends ApiController
{
    public function confirmTransfer(ConfirmCallCenterTransferRequest $request, Order $order, CallCenterOrderExecutionService $service): JsonResponse
    {
        Gate::authorize('execute-call-center-payment', $order);
        $data = $request->validated();

        // تحذير (مش رفض): المبلغ ما بيطابق المتبقي على الطلب — دفعة جزئية أو زيادة بتنقبل، بس الموظف لازم يشوف الفرق.
        $remaining = (float) ($order->invoice?->remainingAmount() ?? $order->total);
        // بس لو لسا في متبقي: إعادة الإرسال بنفس مفتاح الـidempotency (الطلب صار مدفوع) لازم ترجّع نفس الرد بالضبط.
        $warnings = $remaining > 0.001 && abs((float) $data['amount'] - $remaining) > 0.001
            ? [sprintf('المبلغ المدخل (%s) لا يطابق المتبقي على الطلب (%s).', number_format((float) $data['amount'], 2), number_format($remaining, 2))]
            : [];

        $receiptPath = $request->file('receipt')?->store('payment-receipts', 'public');
        try {
            $result = $service->confirmBankTransferAndRelease(
                $order, $data['reference_number'], $data['payment_method_id'], (float) $data['amount'],
                $data['idempotency_key'], (int) $request->user()->id,
                [
                    'transferred_at' => $data['transferred_at'] ?? null,
                    'bank_name' => $data['bank_name'] ?? null,
                    'receipt_path' => $receiptPath,
                ],
            );
        } catch (\Throwable $e) {
            if ($receiptPath) {
                Storage::disk('public')->delete($receiptPath); // ما منخلّي صورة يتيمة لدفعة انرفضت
            }
            throw $e;
        }

        return $this->success(
            $this->executionMessage($result),
            (new CallCenterOrderExecutionResource($result))->resolve($request) + ['warnings' => $warnings],
        );
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
