<?php

namespace App\Exceptions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * خطأ الـAPI الخارجي (/api/v1/external/*) بكود ثابت يقرأه التطبيق الخارجي برمجيًا. بيتحوّل لـ
 * {"error": {"code", "message"}} بـ bootstrap/app.php (نفس الشكل لكل أخطاء هالمسار).
 */
class ExternalApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** أي استثناء بمسار الـAPI الخارجي → رد موحّد. الأخطاء غير المتوقعة (500) ما بتكشف تفاصيل داخلية. */
    // مش render(): Laravel بينادي render($request) تلقائيًا على أي استثناء فيه هالاسم
    public static function toResponse(\Throwable $e): JsonResponse
    {
        [$status, $code, $message, $details, $headers] = match (true) {
            $e instanceof self => [$e->status, $e->errorCode, $e->getMessage(), $e->details, []],
            $e instanceof ValidationException => [422, 'validation_failed', 'The given data was invalid.', $e->errors(), []],
            $e instanceof ThrottleRequestsException => [429, 'rate_limited', 'Too many requests. Slow down and retry later.', [], $e->getHeaders()],
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [404, 'not_found', 'Resource not found.', [], []],
            $e instanceof MethodNotAllowedHttpException => [405, 'method_not_allowed', 'Method not allowed.', [], $e->getHeaders()],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), 'http_error', $e->getMessage() ?: 'Request failed.', [], $e->getHeaders()],
            default => [500, 'server_error', 'Internal server error.', [], []],
        };

        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return new JsonResponse(['error' => $error], $status, $headers, JSON_UNESCAPED_UNICODE);
    }
}
