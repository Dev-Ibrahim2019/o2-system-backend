<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PaymentMethod — Configurable Payment Routing
 *
 * Each payment method is linked to a financial account.
 * No hardcoded account numbers anywhere.
 *
 * Types:
 *   cash     → Main Cashbox (11101)
 *   bank     → Main Bank (11102)
 *   card     → Card Clearing Account
 *   wallet   → Wallet Account
 *   customer → Accounts Receivable (1120) — subledger
 *   employee → Employee Advances (1130) — subledger
 *   supplier → Accounts Payable (2110) — subledger
 */
class PaymentMethod extends Model
{
    protected $fillable = [
        'name',
        'name_en',
        'type',
        'account_id',
        'is_active',
        'is_entity',
        'sort_order',
        'description',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'is_entity'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Scope: only active methods
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: entity methods (customer, employee, supplier)
     */
    public function scopeEntity($query)
    {
        return $query->where('is_entity', true);
    }

    /**
     * Scope: direct payment methods (cash, bank, card, wallet)
     */
    public function scopeDirect($query)
    {
        return $query->where('is_entity', false);
    }
}
