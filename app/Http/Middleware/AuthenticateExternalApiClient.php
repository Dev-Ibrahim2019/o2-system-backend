<?php

namespace App\Http\Middleware;

use App\Exceptions\ExternalApiException;
use App\Models\ExternalApiClient;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * مصادقة التطبيقات الخارجية بـ X-API-Key (مخزّن sha256 — بنقارن الـhash مش المفتاح). المفتاح الملغى
 * (revoked) بيرجع نفس رد المفتاح الغلط عمدًا، عشان ما نكشف إنه كان صالح.
 *
 * Rate limit لكل مفتاح (rate_limit_per_minute) هون مش بـ throttle middleware: Laravel بيرتّب ThrottleRequests
 * قبل الـmiddleware المخصصة حسب الأولوية، فكان بيشتغل قبل ما نعرف أي تطبيق هاد.
 *
 * CORS: الـAPI هاد server-to-server بالأساس. لو الطلب جاي من متصفح (فيه Origin)، الأصل لازم يكون مسجّل
 * لهالتطبيق بالذات (allowed_origins) وإلا 403 — حتى لو كان أصل الواجهة الداخلية.
 */
class AuthenticateExternalApiClient
{
    public const REQUEST_ATTRIBUTE = 'external_api_client';

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header('X-API-Key', '');
        if ($key === '') {
            throw new ExternalApiException(401, 'unauthenticated', 'Missing X-API-Key header.');
        }

        $client = ExternalApiClient::findActiveByKey($key);
        if (! $client) {
            throw new ExternalApiException(401, 'invalid_api_key', 'The API key is invalid or has been revoked.');
        }

        $origin = $request->headers->get('Origin');
        if ($origin !== null && ! $client->allowsOrigin($origin)) {
            throw new ExternalApiException(403, 'origin_not_allowed', 'This origin is not allowed for this API key.');
        }

        // last_used_at بدون كتابة بكل طلب (polling كل كم ثانية) — مرة بالدقيقة بتكفي للمراقبة
        if (! $client->last_used_at || $client->last_used_at->lt(now()->subMinute())) {
            $client->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $client);

        $limit = max(1, (int) $client->rate_limit_per_minute);
        $limiterKey = 'ext-client:'.$client->id;
        if (RateLimiter::tooManyAttempts($limiterKey, $limit)) {
            throw new ThrottleRequestsException('Too Many Attempts.', null, [
                'Retry-After' => RateLimiter::availableIn($limiterKey),
                'X-RateLimit-Limit' => $limit,
                'X-RateLimit-Remaining' => 0,
            ]);
        }
        RateLimiter::hit($limiterKey, 60);

        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, RateLimiter::remaining($limiterKey, $limit)));

        if ($origin !== null) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin', false);
        }

        return $response;
    }
}
