<?php

namespace App\Services\Inventory;

use InvalidArgumentException;

/**
 * تحويل الكميات بين وحدات الصنف ووحدته الأساسية.
 *
 * كل الكميات تُخزَّن بالوحدة الأساسية (القاعدة 5)، والإدخال مسموح بأي وحدة
 * معرّفة للصنف. الحساب بـ bcmath بدقة 6 خانات عشرية (نفس دقة الأعمدة) حتى لا
 * تتراكم أخطاء الفاصلة العائمة في آلاف الحركات اليومية.
 *
 * منطق خالص بلا قاعدة بيانات — قابل للاختبار المنفصل.
 */
final class UnitConverter
{
    public const SCALE = 6;

    /**
     * الكمية بالوحدة الأساسية = الكمية المُدخلة × معامل الوحدة
     * مثال: 2.5 كيلو، والوحدة الأساسية غرام (معامل الكيلو 1000) → 2500 غرام.
     */
    public static function toBase(string|int|float $quantity, string|int|float $factor): string
    {
        self::assertPositiveFactor($factor);

        return self::round(bcmul(self::num($quantity), self::num($factor), self::SCALE + 4));
    }

    /**
     * الكمية بالوحدة المطلوبة = الكمية بالوحدة الأساسية ÷ معامل الوحدة
     * مثال: 2500 غرام ÷ 1000 → 2.5 كيلو.
     */
    public static function fromBase(string|int|float $baseQuantity, string|int|float $factor): string
    {
        self::assertPositiveFactor($factor);

        return self::round(bcdiv(self::num($baseQuantity), self::num($factor), self::SCALE + 4));
    }

    /** تقريب نصف للأعلى (بعيداً عن الصفر) إلى 6 خانات. */
    public static function round(string $value): string
    {
        $offset = '0.'.str_repeat('0', self::SCALE).'5';

        return str_starts_with($value, '-')
            ? bcsub($value, $offset, self::SCALE)
            : bcadd($value, $offset, self::SCALE);
    }

    private static function assertPositiveFactor(string|int|float $factor): void
    {
        if (bccomp(self::num($factor), '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException('معامل التحويل يجب أن يكون أكبر من صفر.');
        }
    }

    /** يحوّل المُدخل إلى نص رقمي صالح لـ bcmath (float → تمثيل عشري ثابت). */
    private static function num(string|int|float $value): string
    {
        if (is_float($value)) {
            $value = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }
        $value = trim((string) $value);

        if (! is_numeric($value)) {
            throw new InvalidArgumentException('قيمة رقمية غير صالحة.');
        }

        return $value === '' || $value === '-0' ? '0' : $value;
    }
}
