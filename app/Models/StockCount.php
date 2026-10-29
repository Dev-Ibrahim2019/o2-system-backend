<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** ورقة جرد فعلي لمخزن: مسودة ← مرحّلة (الترحيل يسجّل تسويات الفروق). تُدار عبر StockCountService. */
class StockCount extends Model
{
    use Auditable;

    public const SOURCE_TYPE = 'stock_count';

    protected $fillable = ['warehouse_id', 'kind', 'business_day', 'notes', 'created_by'];

    protected $casts = [
        'count_date' => 'date:Y-m-d',
        'business_day' => 'date:Y-m-d',
        'variance_value' => 'decimal:6',
        'posted_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
