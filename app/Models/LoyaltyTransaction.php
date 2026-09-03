<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only ledger line. Never updated after creation — a correction is
 * a new 'manual_adjustment' or 'campaign_reversal' row, never an edit to an
 * existing one. A balance is this table's signed sum for an owner, not a
 * stored column, so it can never drift from its own history.
 */
class LoyaltyTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'owner_type', 'owner_id', 'type', 'points', 'status',
        'source_order_id', 'rule_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'points' => 'decimal:3',
        'created_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'source_order_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(LoyaltyRule::class, 'rule_id');
    }

    /** Named creator(), not createdBy() — see OccasionFollowup for why: a
     *  loaded createdBy relation would serialize over the created_by column. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
