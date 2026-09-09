<?php

namespace App\Models;

use App\Services\Accounting\SubledgerService;
use App\Support\Integration\IntegrationReference;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\App;
use LogicException;

class Customer extends Model
{
    use SoftDeletes, Auditable;

    /**
     * Created from CRM / Call Center / (future) Cashier / Website —
     * visible in CRM only, never in Accounting's Customer Accounts.
     */
    public const TYPE_OPERATIONAL = 'operational';

    /**
     * Created from Accounting's own "Add Customer" workflow — visible in
     * both Accounting's Customer Accounts and CRM (CRM is the master
     * directory, so financial customers are always a subset of it).
     */
    public const TYPE_FINANCIAL = 'financial';

    /**
     * Auto-stamped acquisition source for a customer created through the CRM
     * wizard (CrmController::store()) when the request left `source` blank —
     * mirrors CustomerComplaint::CHANNEL_CRM, which does the same for a
     * complaint filed through the same screen. Not in
     * CrmController::CUSTOMER_SOURCE_VALUES: that list is what a user may
     * *pick* for the field; this is what the system fills in when they don't,
     * so it's written directly, not offered as a dropdown option.
     */
    public const SOURCE_CRM = 'crm';

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
        'gender',
        'code',
        'phone',
        'mobile',
        'email',
        'website',
        'address',
        'city',
        'country',
        'engagement_status',
        'group_id',
        'source',
        'status',
        'customer_type',
        'notes',
        'gps_link',
        'branch_id',
        'salesperson_id',
    ];

    protected $casts = [];

    /**
     * Defence in depth only.
     *
     * The eight financial fields no longer exist on this table, so nothing can
     * populate them — but if a future join or select aliases one back onto a
     * Customer instance, it still must not serialise into an API response.
     * The real guarantee is the schema; this is the backstop.
     */
    protected $hidden = [
        'tax_number',
        'currency',
        'risk_level',
        'credit_limit',
        'payment_terms',
        'credit_days',
        'opening_balance',
        'is_opening_balance_posted',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'salesperson_id');
    }

    /**
     * Receivables data — never eager-loaded anywhere, by design.
     *
     * Read it through CustomerFinancialProfileService so the permission is
     * enforced; a bare ->financialProfile() skips that check.
     */
    /**
     * The company/family/agency this customer belongs to, if any.
     *
     * NULL for an individual — the normal case, not a missing value.
     * The group carries the business classification (group_type); the customer
     * carries only its engagement_status.
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'group_id');
    }

    public function financialProfile(): HasOne
    {
        return $this->hasOne(CustomerFinancialProfile::class);
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

    /**
     * Occasions owned by this customer.
     *
     * morphMany since the polymorphic migration — customer_occasions is now
     * shared with CustomerGroup. Note it does not cascade on delete the way
     * the old customer_id foreign key did.
     */
    public function occasions(): MorphMany
    {
        return $this->morphMany(CustomerOccasion::class, 'occasionable');
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(CustomerComplaint::class);
    }

    public function familyMembers(): HasMany
    {
        return $this->hasMany(CustomerFamilyMember::class);
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
     * Credit limit, read from the financial profile.
     *
     * Callers that already hold the profile should use it directly; this
     * exists so the three derived accessors below keep working unchanged for
     * the Accounting screens that rely on them. Returns 0 when a customer has
     * no profile row — the same answer the old default column gave.
     */
    public function creditLimit(): float
    {
        return (float) ($this->financialProfile?->credit_limit ?? 0);
    }

    /**
     * Available credit = credit_limit - current_balance
     */
    public function getAvailableCreditAttribute(): float
    {
        return max(0, $this->creditLimit() - $this->balance);
    }

    /**
     * Is customer over credit limit?
     */
    public function getIsOverLimitAttribute(): bool
    {
        $limit = $this->creditLimit();

        return $limit > 0 && $this->balance > $limit;
    }

    /**
     * Credit usage percentage
     */
    public function getCreditUsagePercentAttribute(): float
    {
        $limit = $this->creditLimit();
        if ($limit <= 0) return 0;
        return min(100, round(($this->balance / $limit) * 100, 1));
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByRiskLevel($query, string $level)
    {
        return $query->whereHas('financialProfile', fn ($q) => $q->where('risk_level', $level));
    }

    /**
     * Financial customers only — the set Accounting's Customer Accounts
     * screen is scoped to. CRM's own queries must NOT use this scope;
     * CRM shows every customer regardless of type.
     */
    public function scopeFinancial($query)
    {
        return $query->where('customer_type', self::TYPE_FINANCIAL);
    }

    public function scopeOperational($query)
    {
        return $query->where('customer_type', self::TYPE_OPERATIONAL);
    }
}
