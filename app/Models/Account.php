<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'name_en',
        'code',
        'type',
        'normal_balance',
        'parent_id',
        'level',
        'allow_posting',
        'is_active',
        'is_system',
        'entity_type',
        'entity_id',
        'sub_type',
        'meta',
        'notes',
        'currency',
        'branch_id',
    ];

    protected $casts = [
        'is_active'     => 'boolean',
        'is_system'     => 'boolean',
        'allow_posting' => 'boolean',
        'level'         => 'integer',
        'meta'          => 'array',
    ];

    // ── Relations ──────────────────────────────────────────────────────

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function childrenRecursive(): HasMany
    {
        return $this->children()->with('childrenRecursive');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    // ── Scopes ─────────────────────────────────────────────────────────

    public function scopePostable($query)
    {
        return $query->where('allow_posting', true)->where('is_active', true);
    }

    public function scopeForEntity($query, string $type, int $id, ?string $subType = null)
    {
        return $query
            ->where('entity_type', $type)
            ->where('entity_id', $id)
            ->when($subType, fn($q) => $q->where('sub_type', $subType));
    }
 
    // ── Helpers ────────────────────────────────────────────────────────

    /**
     * رصيد الحساب — asset/expense: debit طبيعي
     * استخدام مباشر للقيم بدون جلب القيود المرحلة فقط
     * للتقارير الدقيقة استخدم AccountLedgerService
     */

    /**
     * منع نشر قيود على حسابات أم
     */
    public function canPost(): bool
    {
        return $this->allow_posting
            && $this->is_active;
    }

    protected static function booted(): void
    {
        static::creating(function (Account $account) {
            if (empty($account->normal_balance)) {
                $account->normal_balance = in_array($account->type, ['asset', 'expense'])
                    ? 'debit' : 'credit';
            }
        });
    }
}