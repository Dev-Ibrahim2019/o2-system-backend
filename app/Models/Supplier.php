<?php

namespace App\Models;

use App\Traits\HasAccountingEntity;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
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
        'account_id',
        'branch_id',
        'meta',
    ];

    protected $casts = ['meta' => 'array'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function getBalanceAttribute(): float
    {
        return $this->account?->balance ?? 0.0;
    }
}
