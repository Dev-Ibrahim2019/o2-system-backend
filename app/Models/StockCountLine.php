<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سطر جرد: المتوقع (رصيد النظام) والمعدود والفرق بالوحدة الأساسية. counted_quantity فارغ = لم يُعدّ. */
class StockCountLine extends Model
{
    protected $fillable = ['stock_item_id', 'expected_quantity'];

    protected $casts = [
        'expected_quantity' => 'decimal:6',
        'entered_quantity' => 'decimal:6',
        'counted_quantity' => 'decimal:6',
        'variance_quantity' => 'decimal:6',
        'unit_cost' => 'decimal:6',
        'variance_value' => 'decimal:6',
    ];

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class);
    }
}
