<?php

namespace App\Models;

use App\Services\Accounting\SubledgerService;
use App\Support\Integration\IntegrationReference;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\App;
use LogicException;

class Customer extends Model
{
    use SoftDeletes, Auditable;

    private ?float $balanceCache = null;

    protected static function booted(): void
    {
        static::creating(function (Customer $customer): void {
            if (blank($customer->external_ref)) {
                $customer->external_ref = IntegrationReference::customer();
            } elseif (! IntegrationReference::isValid($customer->external_ref, IntegrationReference::CUSTOMER_PREFIX)) {
                throw new LogicException('Customer external_ref must be a typed Customer integration reference.');
            }
        });

        static::updating(function (Customer $customer): void {
            if ($customer->isDirty('external_ref') && filled($customer->getOriginal('external_ref'))) {
                throw new LogicException('Customer external_ref is immutable once issued.');
            }
        });
    }

    protected $fillable = [
        'name',
        'name_en',
        'title',
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
    ];

    protected $casts = [
        'credit_limit'              => 'decimal:3',
        'opening_balance'           => 'decimal:3',
        'is_opening_balance_posted' => 'boolean',
        'credit_days'               => 'integer',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'salesperson_id');
    }

    public function phones(): HasMany
    {
        return $this->hasMany(CustomerPhone::class);
    }

    public function primaryPhone(): HasOne
    {
        return $this->hasOne(CustomerPhone::class)->where('is_primary', true);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function address(): HasOne
    {
        return $this->hasOne(CustomerAddress::class)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderByDesc('last_used_at');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class);
    }

    public function occasions(): HasMany
    {
        return $this->hasMany(CustomerOccasion::class);
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(CustomerComplaint::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Current balance from accounting entries via subledger
     * Uses AR Control Account (1120) via subledger_type='customer'
     */
    public function getBalanceAttribute(): float
    {
        if ($this->balanceCache !== null) {
            return $this->balanceCache;
        }

        return App::make(SubledgerService::class)
            ->getCustomerBalance($this->id);
    }

    public function setBalanceCache(float $balance): self
    {
        $this->balanceCache = $balance;

        return $this;
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
