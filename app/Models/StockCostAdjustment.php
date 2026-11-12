<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** مستند "تسوية سعر": تصحيح سعر دفعات فاتورة مرحّلة صُرف منها (المتبقي والمصروف وقيد المورّد). */
class StockCostAdjustment extends Model
{
    public const SOURCE_TYPE = 'cost_adjustment';

    protected $guarded = ['id'];

    protected $casts = ['total_value' => 'decimal:6', 'posted_at' => 'datetime'];

    public function lines(): HasMany
    {
        return $this->hasMany(StockCostAdjustmentLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
