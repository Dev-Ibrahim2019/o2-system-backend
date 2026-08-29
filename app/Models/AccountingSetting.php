<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * أرقام الحسابات المحاسبية المرتبطة بنقطة البيع (تبويب "أرقام الحسابات" عند الأمين).
 * إعدادات عامة على مستوى المنشأة — key/value، تُدار من لوحة الإدارة وتُعرض بالكاشير.
 */
class AccountingSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'description',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();
        return $setting ? $setting->value : $default;
    }

    public static function set(string $key, ?string $value, ?string $description = null): void
    {
        $data = ['value' => $value];
        if ($description !== null) {
            $data['description'] = $description;
        }

        static::updateOrCreate(['key' => $key], $data);
    }

    public static function allAsMap(): array
    {
        return static::all()->pluck('value', 'key')->all();
    }
}
