<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * تطبيق خارجي بيستهلك /api/v1/external/takeaway. المفتاح الخام ما بينحفظ أبدًا — بيتولّد مرة وحدة
 * (issue()) وبيطلع للمشغّل، وبنخزّن sha256 تبعه بس. سرّ الـwebhook مشفّر لأنه لازم ينقرا للتوقيع.
 */
class ExternalApiClient extends Model
{
    public const KEY_PREFIX = 'o2tw_';

    protected $fillable = [
        'name', 'key_prefix', 'key_hash', 'allowed_origins', 'webhook_url', 'webhook_secret',
        'rate_limit_per_minute', 'last_used_at', 'revoked_at',
    ];

    protected $hidden = ['key_hash', 'webhook_secret'];

    protected $casts = [
        'allowed_origins' => 'array',
        'webhook_secret' => 'encrypted',
        'rate_limit_per_minute' => 'integer',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public static function hashKey(string $plainKey): string
    {
        return hash('sha256', $plainKey);
    }

    /**
     * بينشئ تطبيق جديد وبيرجّع [التطبيق، المفتاح الخام، سرّ الـwebhook الخام أو null]. الخام بيظهر هون بس.
     *
     * @return array{0: self, 1: string, 2: ?string}
     */
    public static function issue(string $name, ?string $webhookUrl = null, array $allowedOrigins = [], int $ratePerMinute = 60): array
    {
        $plainKey = self::KEY_PREFIX.Str::random(40);
        $secret = $webhookUrl ? Str::random(48) : null;

        $client = self::create([
            'name' => $name,
            'key_prefix' => substr($plainKey, 0, 12),
            'key_hash' => self::hashKey($plainKey),
            'allowed_origins' => array_values($allowedOrigins),
            'webhook_url' => $webhookUrl,
            'webhook_secret' => $secret,
            'rate_limit_per_minute' => $ratePerMinute,
        ]);

        return [$client, $plainKey, $secret];
    }

    public static function findActiveByKey(string $plainKey): ?self
    {
        return self::query()
            ->where('key_hash', self::hashKey($plainKey))
            ->whereNull('revoked_at')
            ->first();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** Origin من المتصفح مسموح بس لو مسجّل لهالتطبيق (بدون / بالآخر، حساس لحالة الأحرف بالـscheme/host). */
    public function allowsOrigin(string $origin): bool
    {
        $origin = rtrim(strtolower($origin), '/');

        return collect($this->allowed_origins ?? [])
            ->map(fn ($o) => rtrim(strtolower((string) $o), '/'))
            ->contains($origin);
    }
}
