<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded discrepancy between an incoming customer name and the name
 * already stored against the same phone number.
 *
 * Opening one never changes a customer — that only happens when an operator
 * resolves the ticket through IdentityConflictService.
 */
class CustomerIdentityConflict extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_DISMISSED = 'dismissed';

    public const RESOLUTION_KEPT_ORIGINAL = 'kept_original';
    public const RESOLUTION_RENAMED_CUSTOMER = 'renamed_customer';
    public const RESOLUTION_CREATED_NEW_CUSTOMER = 'created_new_customer';
    public const RESOLUTION_MARKED_SHARED_NUMBER = 'marked_shared_number';

    public const RESOLUTIONS = [
        self::RESOLUTION_KEPT_ORIGINAL,
        self::RESOLUTION_RENAMED_CUSTOMER,
        self::RESOLUTION_CREATED_NEW_CUSTOMER,
        self::RESOLUTION_MARKED_SHARED_NUMBER,
    ];

    public const CHANNELS = ['call_center', 'pos_instant', 'pos_family', 'website'];

    protected $fillable = [
        'customer_id',
        'source_channel',
        'source_order_id',
        'incoming_name',
        'incoming_phone_normalized',
        'status',
        'resolution',
        'created_customer_id',
        'resolution_note',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'source_order_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** The customer a split resolution created, if this ticket produced one. */
    public function createdCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'created_customer_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
