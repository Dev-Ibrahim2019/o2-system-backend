<?php

namespace App\Services\Support;

use InvalidArgumentException;

class PhoneNormalizer
{
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

        if (preg_match('/^0(59|56)\d{7}$/', $digits)) {
            $digits = '970' . substr($digits, 1);
        } elseif (preg_match('/^(59|56)\d{7}$/', $digits)) {
            $digits = '970' . $digits;
        }

        if (! preg_match('/^[1-9]\d{7,14}$/', $digits)) {
            throw new InvalidArgumentException('رقم الهاتف غير صالح أو لا يمكن تحويله إلى E.164.');
        }

        return '+' . $digits;
    }

    public function legacyValue(string $normalizedPhone): string
    {
        return str_starts_with($normalizedPhone, '+970')
            ? substr($normalizedPhone, 4)
            : $normalizedPhone;
    }
}
