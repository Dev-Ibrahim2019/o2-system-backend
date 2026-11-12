<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** نقص معلّق: خروج لم تكفه الدفعات (البيع لا يتوقف)، بتكلفة مؤقتة تُصحَّح عند تسويته من دفعة واردة. */
class StockLayerShortage extends Model
{
    public const OPEN = 'open';

    public const SETTLED = 'settled';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:6',
        'qty_open' => 'decimal:6',
        'provisional_unit_cost' => 'decimal:6',
        'settled_at' => 'datetime',
    ];

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }
}
