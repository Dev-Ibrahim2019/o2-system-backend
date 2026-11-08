<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** خصم المخزون لطلب مدفوع واحد (انظر SaleConsumptionService). */
class StockSalePosting extends Model
{
    public const POSTED = 'posted';

    public const PARTIAL = 'partial';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public const REVERSED = 'reversed';

    protected $guarded = ['id'];

    protected $casts = [
        'issues' => 'array',
        'total_cost' => 'decimal:6',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
