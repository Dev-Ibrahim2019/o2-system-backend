<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evidence for reference-based manual confirmations only (bank/card/wallet).
 * Entity-account debits are recorded through payments and their existing
 * entity_type/entity_id subledger fields, never through this model.
 */
class PaymentConfirmation extends Model
{
    public const PAYMENT_POLICY = Order::PAYMENT_POLICY_MANUAL_CONFIRMATION;
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'order_id', 'payment_method_id', 'reference_number', 'normalized_reference_number',
        'amount', 'status',
        'idempotency_key', 'confirmed_by', 'confirmed_at', 'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:3',
        'confirmed_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
