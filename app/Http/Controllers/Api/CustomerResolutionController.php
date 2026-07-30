<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Services\CallCenter\CustomerResolutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CustomerResolutionController extends ApiController
{
    public function __invoke(Request $request, CustomerResolutionService $resolution): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:40']]);

        try {
            return $this->success('Customer phone resolved', $resolution->resolve($data['phone']));
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            $requestId = (string) Str::uuid();
            Log::error('Call-center customer resolution failed', [
                'request_id' => $requestId,
                'user_id' => $request->user()?->id,
                'exception' => $exception,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'تعذر البحث عن العميل حاليًا',
                'request_id' => $requestId,
            ], 500);
        }
    }
}
