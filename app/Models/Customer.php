<?php

namespace App\Models;

use App\Traits\HasAccountingEntity;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes, HasAccountingEntity, Auditable;

    protected $fillable = [
        'name',
        'name_en',
        'code',
        'tax_number',
        'phone',
        'email',
        'address',
        'status',
        'credit_limit',
        'account_id',
        'branch_id',
        'meta',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:3',
        'meta' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * رصيد العميل (موجب = مدين للشركة)
     */
    public function getBalanceAttribute(): float
    {
        return $this->account?->balance ?? 0.0;
    }

    /**
     * هل تجاوز حد الائتمان؟
     */
    public function isOverCreditLimit(): bool
    {
        return $this->credit_limit > 0 && $this->balance > $this->credit_limit;
    }
}
