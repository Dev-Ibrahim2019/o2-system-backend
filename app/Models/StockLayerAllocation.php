<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ربط خروج بدفعة (أو عودته إليها). ثابت لا يُعدَّل ولا يُحذف (Trigger)؛ العكس سطر عكسي. */
class StockLayerAllocation extends Model
{
    public const UPDATED_AT = null;

    public const KIND_CONSUME = 'consume';

    public const KIND_SETTLE = 'settle';

    public const KIND_RESTORE = 'restore';

    public const KIND_VOID = 'void';

    public const KIND_SPLIT = 'split';

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:6',
        'unit_cost' => 'decimal:6',
        'total_cost' => 'decimal:6',
        'created_at' => 'datetime',
    ];

    public function layer(): BelongsTo
    {
        return $this->belongsTo(StockLayer::class, 'layer_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }
}
