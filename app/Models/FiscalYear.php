<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Scopes\BranchScope;

class FiscalYear extends Model
{
    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'status',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    // ── Scopes ─────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeClosed($query)
    {
        return $query->where('status', 'closed');
    }

    // ── Static Helpers ─────────────────────────────────────────────────────────

    /**
     * Find the active fiscal year for a given date.
     */
    public static function findForDate($date): ?self
    {
        return static::where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Get the currently active fiscal year.
     */
    public static function getActive(): ?self
    {
        return static::where('status', 'active')
            ->latest('start_date')
            ->first();
    }

    // ── Instance Methods ───────────────────────────────────────────────────────

    /**
     * Check if the fiscal year is open.
     */
    public function isOpen(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if a given date falls within this fiscal year.
     */
    public function containsDate($date): bool
    {
        $date = $date instanceof \Carbon\Carbon ? $date : \Carbon\Carbon::parse($date);
        return $date->gte($this->start_date) && $date->lte($this->end_date);
    }

    /**
     * Check if a date is in a closed fiscal year.
     */
    public static function isDateClosed($date): bool
    {
        return static::where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->where('status', 'closed')
            ->exists();
    }

    /**
     * Close the fiscal year.
     */
    public function close(): self
    {
        $this->update(['status' => 'closed']);
        return $this->fresh();
    }
}
