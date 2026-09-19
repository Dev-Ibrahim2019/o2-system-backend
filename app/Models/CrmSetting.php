<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single, global row backing CRM's module-wide toggles — see the
 * create_crm_settings_table migration for what each field actually does and
 * why. Singleton shape (current()/set()), same precedent as CrmOrderDelaySetting.
 */
class CrmSetting extends Model
{
    protected $fillable = [
        'enabled', 'auto_register_pos_customers', 'monthly_revenue_target', 'max_cancellation_rate_pct', 'updated_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'auto_register_pos_customers' => 'boolean',
        'monthly_revenue_target' => 'float',
        'max_cancellation_rate_pct' => 'float',
    ];

    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create([
            'enabled' => true,
            'auto_register_pos_customers' => true,
        ]);
    }

    public static function set(array $data, int $userId): self
    {
        $row = static::query()->first() ?? new static();
        $row->fill([...$data, 'updated_by' => $userId]);
        $row->save();

        return $row;
    }
}
