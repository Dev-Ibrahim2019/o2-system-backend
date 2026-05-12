<?php

namespace App\Models;

use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Entry extends Model
{
    protected $fillable = [
        'transaction_id',
        'account_id',
        'debit',
        'credit',
        'description',
        'cost_center_id',
        'sort_order',
    ];

    protected $casts = [
        'debit'      => 'decimal:3',
        'credit'     => 'decimal:3',
        'sort_order' => 'integer',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * هل السطر مدين
     */
    public function isDebit(): bool
    {
        return $this->debit > 0;
    }

    /**
     * هل السطر دائن
     */
    public function isCredit(): bool
    {
        return $this->credit > 0;
    }

    /**
     * المبلغ الصافي (양موجب = مدين، سالب = دائن)
     */
    public function getAmountAttribute(): float
    {
        return (float) ($this->debit - $this->credit);
    }
}
