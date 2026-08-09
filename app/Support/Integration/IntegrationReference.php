<?php

namespace App\Support\Integration;

use Illuminate\Support\Str;

final class IntegrationReference
{
    public const ORDER_PREFIX = 'ord';

    public const CUSTOMER_PREFIX = 'cus';

    public const OUTBOX_PREFIX = 'evt';

    public static function order(): string
    {
        return self::generate(self::ORDER_PREFIX);
    }

    public static function customer(): string
    {
        return self::generate(self::CUSTOMER_PREFIX);
    }

    public static function outbox(): string
    {
        return self::generate(self::OUTBOX_PREFIX);
    }

    public static function isValid(string $reference, ?string $expectedPrefix = null): bool
    {
        $prefix = $expectedPrefix ?? '(?:ord|cus|evt)';

        return preg_match('/^'.$prefix.'_[0-9a-f]{32}$/', $reference) === 1;
    }

    private static function generate(string $prefix): string
    {
        return $prefix.'_'.str_replace('-', '', (string) Str::uuid());
    }
}
