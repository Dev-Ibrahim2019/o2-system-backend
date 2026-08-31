<?php

namespace App\Services\Support;

use InvalidArgumentException;

class PhoneNormalizer
{
    /**
     * Throws on an unusable number.
     *
     * Nine call sites depend on that (CustomerIdentityService, the call-center
     * services, the backfill commands) — they turn the exception into a
     * validation error the operator sees. Channels that must never fail an
     * order over a phone number use tryNormalize() instead.
     */
    public function normalize(string $phone): string
    {
        $trimmed = trim($phone);
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($digits === '') {
            throw new InvalidArgumentException('رقم الهاتف مطلوب.');
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // Every 05x mobile prefix, not just 059/056. Widened deliberately:
        // 0500000002 came off a real order (see the Gate 0 audit) and used to
        // be rejected outright, which left the order with no identity at all.
        if (preg_match('/^05\d{8}$/', $digits)) {
            $digits = '970' . substr($digits, 1);
        } elseif (preg_match('/^5\d{8}$/', $digits)) {
            $digits = '970' . $digits;
        }

        if (! preg_match('/^[1-9]\d{7,14}$/', $digits)) {
            throw new InvalidArgumentException('رقم الهاتف غير صالح أو لا يمكن تحويله إلى E.164.');
        }

        return '+' . $digits;
    }

    /**
     * Same rules, no exception — null when the number cannot be normalised.
     *
     * For channels with no human standing by to correct the input: a cashier
     * closing a sale must not be blocked, or even shown an error, because a
     * phone number was mistyped. The order completes; the identity is simply
     * not resolved.
     */
    public function tryNormalize(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        try {
            return $this->normalize($phone);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function legacyValue(string $normalizedPhone): string
    {
        return str_starts_with($normalizedPhone, '+970')
            ? substr($normalizedPhone, 4)
            : $normalizedPhone;
    }
}
