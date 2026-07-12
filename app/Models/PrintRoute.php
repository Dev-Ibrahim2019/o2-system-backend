<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PrintRoute extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'user_id',
        'category_id',
        'item_id',
        'printer_id',
        'pos_register_id',
        'hospitality_device_id',
        'action_type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ── Relationships ────────────────────────────────────────

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function printer()
    {
        return $this->belongsTo(Printer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function category()
    {
        return $this->belongsTo(Department::class, 'category_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function posRegister()
    {
        return $this->belongsTo(PosRegister::class);
    }

    public function hospitalityDevice()
    {
        return $this->belongsTo(HospitalityDevice::class);
    }

    // ── Accessors ────────────────────────────────────────────

    /**
     * النطاق المُستنتَج تلقائياً من البيانات
     * ITEM إذا كان item_id مملوءاً، وإلا CATEGORY
     */
    public function getScopeAttribute(): string
    {
        return $this->item_id ? 'ITEM' : 'CATEGORY';
    }

    // ── Scopes ───────────────────────────────────────────────

    public function scopeActiveForBranch($query, int $branchId)
    {
        return $query->where('branch_id', $branchId)
                     ->where('is_active', true);
    }

    public function scopeForUser($query, ?int $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->whereNull('user_id')
              ->orWhere('user_id', $userId);
        });
    }

    // ── Helpers ──────────────────────────────────────────────

    public function matchesItem(int $itemId, int $categoryId, ?int $userId = null): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->user_id !== null && $this->user_id !== $userId) {
            return false;
        }

        if ($this->item_id !== null && $this->item_id == $itemId) {
            return true;
        }

        if ($this->category_id !== null && $this->category_id == $categoryId) {
            return true;
        }

        return false;
    }
}
