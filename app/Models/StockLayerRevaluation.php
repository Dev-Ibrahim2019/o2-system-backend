<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** فرق تكلفة على دفعة أو على جزء مصروف منها (تسوية سعر / تسوية نقص معلّق)، مع حركة التصحيح في الدفتر. */
class StockLayerRevaluation extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:6',
        'old_unit_cost' => 'decimal:6',
        'new_unit_cost' => 'decimal:6',
        'value' => 'decimal:6',
        'created_at' => 'datetime',
    ];
}
