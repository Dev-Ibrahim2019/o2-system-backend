<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCostAdjustmentLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'old_unit_cost' => 'decimal:6',
        'new_unit_cost' => 'decimal:6',
        'quantity' => 'decimal:6',
        'remaining_quantity' => 'decimal:6',
        'value' => 'decimal:6',
    ];

    public function layer(): BelongsTo
    {
        return $this->belongsTo(StockLayer::class, 'layer_id');
    }
}
