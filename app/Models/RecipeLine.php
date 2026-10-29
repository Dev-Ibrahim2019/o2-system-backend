<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** مكوّن في وصفة: الكمية المُدخلة بوحدتها، و base_quantity بالوحدة الأساسية للمكوّن (أساس كل الحسابات). */
class RecipeLine extends Model
{
    protected $fillable = ['stock_item_id', 'unit_id', 'quantity', 'base_quantity'];

    protected $casts = [
        'quantity' => 'decimal:6',
        'base_quantity' => 'decimal:6',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
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
