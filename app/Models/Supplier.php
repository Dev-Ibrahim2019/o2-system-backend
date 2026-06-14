<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Services\Accounting\SubledgerService;
use Illuminate\Support\Facades\App;

class Supplier extends Model
{
    use SoftDeletes, Auditable;

    protected $fillable = [
        'name',
        'name_en',
        'code',
        'tax_number',
        'phone',
        'mobile',
        'email',
        'address',
        'city',
        'category',
        'currency',
        'status',
        'credit_limit',
        'payment_terms',
        'opening_balance',
        'is_opening_balance_posted',
        'notes',
        'gps_link',
        'branch_id',
    ];

    protected $casts = [
        'credit_limit'              => 'decimal:3',
        'opening_balance'           => 'decimal:3',
        'is_opening_balance_posted' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'source_id')
            ->where('source_type', static::class);
    }

    /**
     * رصيد المورد الحالي من حساب 2110 عبر subledger
     */
    public function getBalanceAttribute(): float
    {
        return App::make(SubledgerService::class)
            ->getSupplierBalance($this->id);
    }

    /**
     * حالة الرصيد: دائن (مورد دائن) أو مدين (مدفوعات زائدة)
     */
    public function getBalanceTypeAttribute(): string
    {
        return $this->balance >= 0 ? 'credit' : 'debit';
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeWithOverdue($query)
    {
        return $query->whereHas('transactions', function ($q) {
            $q->where('date', '<', now()->subDays(30))
                ->where('status', 'posted');
        });
    }
}
