<?php

namespace App\Models;

use App\Services\Accounting\SubledgerService;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\App;

class Customer extends Model
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
        'website',
        'address',
        'city',
        'country',
        'category',
        'currency',
        'status',
        'risk_level',
        'credit_limit',
        'payment_terms',
        'credit_days',
        'opening_balance',
        'is_opening_balance_posted',
        'notes',
        'gps_link',
        'branch_id',
        'salesperson_id',
        'loyalty_points',
    ];

    protected $casts = [
        'credit_limit'              => 'decimal:3',
        'opening_balance'           => 'decimal:3',
        'is_opening_balance_posted' => 'boolean',
        'credit_days'               => 'integer',
        'loyalty_points'            => 'integer',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'salesperson_id');
    }

    public function addresses()
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function occasions()
    {
        return $this->hasMany(CustomerOccasion::class);
    }

    public function customerNotes()
    {
        return $this->hasMany(CustomerNote::class);
    }

    public function complaints()
    {
        return $this->hasMany(CustomerComplaint::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function address()
    {
        return $this->hasOne(CustomerAddress::class)->where('is_default', true);
    }

    /**
     * Current balance from accounting entries via subledger
     * Uses AR Control Account (1120) via subledger_type='customer'
     */
    public function getBalanceAttribute(): float
    {
        return App::make(SubledgerService::class)
            ->getCustomerBalance($this->id);
    }

    /**
     * Available credit = credit_limit - current_balance
     */
    public function getAvailableCreditAttribute(): float
    {
        return max(0, (float) $this->credit_limit - $this->balance);
    }

    /**
     * Is customer over credit limit?
     */
    public function getIsOverLimitAttribute(): bool
    {
        return $this->credit_limit > 0 && $this->balance > $this->credit_limit;
    }

    /**
     * Credit usage percentage
     */
    public function getCreditUsagePercentAttribute(): float
    {
        if ($this->credit_limit <= 0) return 0;
        return min(100, round(($this->balance / $this->credit_limit) * 100, 1));
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByRiskLevel($query, string $level)
    {
        return $query->where('risk_level', $level);
    }
}
