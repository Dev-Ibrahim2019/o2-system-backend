<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Transaction extends Model
{
    use Auditable;

    protected $fillable = [
        'transaction_number',
        'date',
        'reference',
        'type',
        'status',
        'description',
        'notes',
        'branch_id',
        'user_id',
        'source_type',
        'source_id',
        'reversal_of_id',
        'is_reversal',
        'period_id',
        'currency',
        'exchange_rate',
        'approved_by',
        'approved_at',
        'posted_at',
    ];

    protected $casts = [
        'date'          => 'date',
        'posted_at'     => 'datetime',
        'approved_at'   => 'datetime',
        'is_reversal'   => 'boolean',
        'exchange_rate' => 'decimal:6',
    ];

    // ── Relations ──────────────────────────────────────────────────────

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'reversal_of_id');
    }

    public function reversal(): HasMany
    {
        return $this->hasMany(Transaction::class, 'reversal_of_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    // ── Scopes ─────────────────────────────────────────────────────────

    public function scopePosted($query)
    {
        return $query->where('status', 'posted');
    }

    public function scopeForSource($query, Model $source)
    {
        return $query
            ->where('source_type', get_class($source))
            ->where('source_id', $source->id);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    public function getTotalDebitAttribute(): float
    {
        return (float) $this->entries->sum('debit');
    }

    public function getTotalCreditAttribute(): float
    {
        return (float) $this->entries->sum('credit');
    }

    public function isBalanced(): bool
    {
        $entries = $this->entries->count() ? $this->entries : $this->entries()->get();
        return abs($entries->sum('debit') - $entries->sum('credit')) < 0.001;
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    public function getSourceLabelAttribute(): ?string
    {
        return $this->source_type ? class_basename($this->source_type) : null;
    }

    public static function generateNumber(string $prefix = 'JV'): string
    {
        $today = now()->format('Ymd');
        $key   = "{$prefix}-{$today}-";

        $last = static::where('transaction_number', 'like', $key . '%')
            ->orderByDesc('id')
            ->value('transaction_number');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $key . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    public function post(): void
    {
        $this->update(['status' => 'posted', 'posted_at' => now()]);
    }

    public function cancel(): void
    {
        $this->update(['status' => 'cancelled']);
    }
}
