<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * إعدادات الخصومات — نظام key-value للتكوين
 */
class DiscountSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'description',
    ];

    /**
     * الحصول على قيمة إعداد
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();
        return $setting ? $setting->value : $default;
    }

    /**
     * تعيين قيمة إعداد
     */
    public static function set(string $key, string $value, ?string $description = null): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'description' => $description]
        );
    }

    /**
     * الحصول على كود حساب خصومات المبيعات
     */
    public static function getSalesDiscountsAccountCode(): string
    {
        return static::get('sales_discounts_account_code', '4120');
    }

    /**
     * الحصول على أقصى نسبة خصم مسموحة
     */
    public static function getMaxDiscountPercent(): float
    {
        return (float) static::get('max_discount_percent', 100);
    }
}
