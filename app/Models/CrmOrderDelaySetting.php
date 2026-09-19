<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single, global row backing the CRM order-delay alert threshold — see
 * the create_crm_order_delay_settings_table migration. Modelled as a
 * singleton (current()/set()) rather than a general key-value store: this
 * table only ever holds one fact, unlike DiscountSetting's key/value shape.
 */
class CrmOrderDelaySetting extends Model
{
    protected $fillable = ['threshold_minutes', 'updated_by'];

    /**
     * The current alert threshold, in minutes. Falls back to creating the
     * seeded row if it is ever missing (should not normally happen — the
     * migration seeds it) rather than silently defaulting in memory, so the
     * value read here always matches what the settings screen shows.
     */
    public static function current(): int
    {
        $row = static::query()->first();

        return (int) ($row?->threshold_minutes ?? static::query()->create(['threshold_minutes' => 30])->threshold_minutes);
    }

    public static function set(int $minutes, int $userId): int
    {
        $row = static::query()->first() ?? new static();
        $row->fill(['threshold_minutes' => $minutes, 'updated_by' => $userId]);
        $row->save();

        return $minutes;
    }
}
