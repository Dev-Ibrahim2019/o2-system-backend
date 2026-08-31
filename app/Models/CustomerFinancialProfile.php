<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's receivables data, deliberately kept off the customer row.
 *
 * Load it only through CustomerFinancialProfileService, which enforces the
 * permission. Reaching for this model directly bypasses that check — the
 * separation is physical, but the gate still has to be walked through.
 */
class CustomerFinancialProfile extends Model
{
    /** The eight columns that used to live on `customers`. */
    public const FIELDS = [
        'tax_number',
        'currency',
        'risk_level',
        'credit_limit',
        'payment_terms',
        'credit_days',
        'opening_balance',
        'is_opening_balance_posted',
    ];

    protected $fillable = [
        'customer_id',
        ...self::FIELDS,
    ];

    protected $casts = [
        'credit_limit' => 'decimal:3',
        'opening_balance' => 'decimal:3',
        'credit_days' => 'integer',
        'is_opening_balance_posted' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
