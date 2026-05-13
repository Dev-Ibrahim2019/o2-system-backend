<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $fillable = [
        'name',
        'code',
        'type',
        'normal_balance',
        'parent_id',
        'level',
        'allow_posting',
        'is_active',
        'is_system',
        'notes',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'is_system'      => 'boolean',
        'allow_posting'  => 'boolean',
        'level'          => 'integer',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    // شجرة كاملة بشكل recursive
    public function childrenRecursive(): HasMany
    {
        return $this->children()->with('childrenRecursive');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePostable($query)
    {
        return $query->where('allow_posting', true);
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * حساب رصيد الحساب
     * asset + expense → debit - credit
     * liability + equity + revenue → credit - debit
     */
    public function getBalanceAttribute(): float
    {
        $debit  = $this->entries()->sum('debit');
        $credit = $this->entries()->sum('credit');

        return in_array($this->type, ['asset', 'expense'])
            ? (float)($debit - $credit)
            : (float)($credit - $debit);
    }

    /**
     * إجمالي المدين
     */
    public function getTotalDebitAttribute(): float
    {
        return (float) $this->entries()->sum('debit');
    }

    /**
     * إجمالي الدائن
     */
    public function getTotalCreditAttribute(): float
    {
        return (float) $this->entries()->sum('credit');
    }

    /**
     * هل الحساب له أولاد (حساب أم)
     */
    public function getIsParentAttribute(): bool
    {
        return $this->children()->exists();
    }

    /**
     * ضبط normal_balance تلقائياً بحسب النوع
     */
    protected static function booted(): void
    {
        static::creating(function (Account $account) {
            if (empty($account->normal_balance)) {
                $account->normal_balance = in_array($account->type, ['asset', 'expense'])
                    ? 'debit'
                    : 'credit';
            }
        });
    }
}
