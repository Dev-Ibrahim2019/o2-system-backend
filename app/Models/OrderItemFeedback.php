<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One 1–5 rating (plus an optional note) for a single order_item — the
 * item-level sibling of OrderFeedback. Upserted by order_item_id, never
 * appended to.
 */
class OrderItemFeedback extends Model
{
    protected $table = 'order_item_feedback';

    protected $fillable = [
        'order_item_id',
        'order_id',
        'rating',
        'notes',
        'complaint_id',
        'recorded_by',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** The complaint this rating was escalated into, if any. */
    public function complaint(): BelongsTo
    {
        return $this->belongsTo(CustomerComplaint::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
