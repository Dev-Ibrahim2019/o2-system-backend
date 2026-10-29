<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** وحدة بديلة لصنف: 1 من هذه الوحدة = factor من الوحدة الأساسية للصنف. */
class StockItemUnit extends Model
{
    use Auditable;

    protected $fillable = ['stock_item_id', 'unit_id', 'factor'];

    protected $casts = ['factor' => 'decimal:6'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class, 'unit_id');
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }
}
