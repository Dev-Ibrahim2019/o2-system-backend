<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** رصيد دفعة في مخزن. للقراءة فقط من التطبيق؛ يحدّثه StockLedger داخل معاملة الحركة. */
class StockBatchBalance extends Model
{
    public const CREATED_AT = null;

    protected $guarded = ['*'];

    protected $casts = [
        'quantity' => 'decimal:6',
        'arrived_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'batch_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
