<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * ط¨ظ†ط¯ ط§ظ„ط·ظ„ط¨ â€” ظٹط­ظپط¸ ط§ظ„ط§ط³ظ… ظˆط§ظ„ط³ط¹ط± ظˆظ‚طھ ط§ظ„ط¥ط¶ط§ظپط© (ظ…ط³طھظ‚ظ„ ط¹ظ† طھط؛ظٹظ‘ط± ط³ط¹ط± ط§ظ„ظ‚ط§ط¦ظ…ط© ظ„ط§ط­ظ‚ط§ظ‹).
 * department_id ظٹط£طھظٹ ظ…ظ† items.department_id ظˆظٹظڈط³طھط®ط¯ظ… ط¹ظ†ط¯ طھظ‚ط³ظٹظ… ط§ظ„ط·ظ„ط¨ ظ„ظ„ط£ظ‚ط³ط§ظ….
 */
class OrderItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'item_id',
        'department_id',
        'item_name',
        'item_name_ar',
        'price',
        'original_price',
        'final_price',
        'discount_amount',
        'discount_percent',
        'discount_id',
        'discount_apply_strategy',
        'tax_rate',
        'tax_amount',
        'quantity',
        'weight_grams',
        'total',
        'status',
        'notes',
        'sent_to_kitchen_at',
        'is_printed_direct',
        'is_takeaway',
        'item_prepared_at',
        'prepared_duration_seconds',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'quantity' => 'decimal:2',
        'weight_grams' => 'integer',
        'total' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'sent_to_kitchen_at' => 'datetime',
        'is_printed_direct' => 'boolean',
        'is_takeaway' => 'boolean',
        'item_prepared_at' => 'datetime',
        'prepared_duration_seconds' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function ticketItem(): HasOne
    {
        return $this->hasOne(ProductionTicketItem::class);
    }
}
