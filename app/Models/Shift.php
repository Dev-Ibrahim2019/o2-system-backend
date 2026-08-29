<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Scopes\BranchScope;

class Shift extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    protected $fillable = [
        'branch_id',
        'fiscal_year_id',
        'opened_by',
        'closed_by',
        'date',
        'opened_at',
        'closed_at',
        'status',
        'opening_balance',
        'closing_balance',
        'total_sales',
    ];

    protected $casts = [
        'date' => 'date',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_balance' => 'decimal:2',
        'closing_balance' => 'decimal:2',
        'total_sales' => 'decimal:2',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    // ── Scopes ─────────────────────────────────────────────────────────────────

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeForBranch($query, int $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeForDate($query, $date)
    {
        return $query->whereDate('date', $date);
    }

    // ── Static Helpers ─────────────────────────────────────────────────────────

    /**
     * Get the currently open shift for a branch on today's date.
     */
    public static function getOpenForBranch(int $branchId): ?self
    {
        return static::where('branch_id', $branchId)
            ->where('status', 'open')
            ->whereDate('date', now()->toDateString())
            ->first();
    }

    /**
     * Get or create an open shift for a branch today.
     * قفل + معاملة عشان طلبين متزامنين (مثلاً أول أوردر باليوم من كاشيرين
     * مختلفين بنفس اللحظة) ما ينشئوا يوميتين مفتوحتين لنفس الفرع/اليوم.
     */
    public static function getOrCreateToday(int $branchId, int $userId, float $openingBalance = 0): self
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($branchId, $userId, $openingBalance) {
            $existing = static::where('branch_id', $branchId)
                ->where('status', 'open')
                ->whereDate('date', now()->toDateString())
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            // البحث عن السنة المالية النشطة لتاريخ اليوم
            $fiscalYear = FiscalYear::findForDate(now());

            return static::create([
                'branch_id' => $branchId,
                'fiscal_year_id' => $fiscalYear?->id,
                'opened_by' => $userId,
                'date' => now()->toDateString(),
                'opened_at' => now(),
                'status' => 'open',
                'opening_balance' => $openingBalance,
            ]);
        });
    }

    // ── Instance Methods ───────────────────────────────────────────────────────

    /**
     * Close the current shift and calculate total sales.
     */
    public function close(int $userId, float $closingBalance = 0): self
    {
        $totalSales = $this->orders()
            ->where('status', 'paid')
            ->sum('total');

        $this->update([
            'status' => 'closed',
            'closed_by' => $userId,
            'closed_at' => now(),
            'closing_balance' => $closingBalance,
            'total_sales' => $totalSales,
        ]);

        return $this->fresh();
    }
}
