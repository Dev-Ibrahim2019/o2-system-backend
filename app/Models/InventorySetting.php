<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * سطر واحد لإعدادات المخزون القابلة للتعديل من الواجهة — نفس نمط CrmSetting (current()/set()).
 * التعديلات تُسجَّل في سجل التدقيق عبر Auditable.
 */
class InventorySetting extends Model
{
    use Auditable;

    protected $fillable = [
        'allow_negative_stock', 'variance_alert_percent', 'updated_by',
        'price_alert_percent', 'deduct_sales', 'deduct_sales_from',
    ];

    protected $casts = [
        'allow_negative_stock' => 'boolean',
        'deduct_sales' => 'boolean',
        'deduct_sales_from' => 'datetime',
        'variance_alert_percent' => 'decimal:2',
    ];

    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create(['allow_negative_stock' => false]);
    }

    public static function set(array $data, int $userId): self
    {
        $row = static::current();
        $row->fill([...$data, 'updated_by' => $userId])->save();

        return $row;
    }
}
