<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponses
{
    /**
     * Unified Success Response
     * Format: { success: true, message, data, errors: null }
     */
    protected function success($message, $data = [], $statusCode = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
            'errors'  => null,
        ], $statusCode);
    }

    /**
     * Unified Error Response
     * Format: { success: false, message, data: null, errors }
     */
    protected function error($message, $statusCode = 400, $errors = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data'    => null,
            'errors'  => $errors,
        ], $statusCode);
    }

    /**
     * Compatibility wrapper for existing code
     */
    protected function ok($message, $data = []): JsonResponse
    {
        return $this->success($message, $data);
    }
}
