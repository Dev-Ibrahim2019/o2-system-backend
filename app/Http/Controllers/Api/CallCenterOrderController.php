<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\StoreCallCenterOrderRequest;
use App\Services\CallCenter\CallCenterOrderCreationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CallCenterOrderController extends ApiController
{
    public function store(StoreCallCenterOrderRequest $request, CallCenterOrderCreationService $service): JsonResponse
    {
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
