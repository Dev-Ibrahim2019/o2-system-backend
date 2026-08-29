<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * نموذج الخصم — يمثل خصماً واحداً في نظام إدارة الخصومات
 * 
 * أنواع الخصم:
 * - percentage: خصم بنسبة مئوية
 * - fixed_amount: خصم بمبلغ ثابت
 * - price_override: تجاوز السعر (سعر جديد)
 * - buy_x_get_y: اشتر X واحصل على Y (للتوسع المستقبلي)
 */
class Discount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'name_ar',
        'code',
        'description',
        'discount_type',
        'apply_strategy',
        'value',
        'priority',
        'start_date',
        'end_date',
        'is_active',
        'created_by',
        'branch_id',
        'buy_quantity',
        'get_quantity',
        'get_discount_percent',
        'max_discount_amount',
        'min_order_amount',
        'usage_limit',
    ];

    protected $casts = [
        'value' => 'decimal:3',
        'apply_strategy' => 'string',
        'priority' => 'integer',
        'is_active' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
        'buy_quantity' => 'integer',
        'get_quantity' => 'integer',
        'get_discount_percent' => 'decimal:2',
        'max_discount_amount' => 'decimal:3',
        'min_order_amount' => 'decimal:3',
        'usage_limit' => 'integer',
    ];

    // ── Scopes ──────────────────────────────────────────────────────────

    /**
     * الخصومات النشطة حالياً (ضمن فترة الصلاحية ومفعلة)
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('start_date')
                    ->orWhere('start_date', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            });
    }

    /**
     * الخصومات المنتهية الصلاحية
     */
    public function scopeExpired($query)
    {
        return $query->where('end_date', '<', now());
    }

    /**
     * الخصومات حسب الأولوية (الأصغر أولاً)
     */
    public function scopeByPriority($query, string $direction = 'asc')
    {
        return $query->orderBy('priority', $direction);
    }

    // ── Relations ───────────────────────────────────────────────────────

    public function targets(): HasMany
    {
        return $this->hasMany(DiscountTarget::class);
    }

    public function exclusions(): HasMany
    {
        return $this->hasMany(DiscountExclusion::class);
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(DiscountUsageLog::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * هل الخصم ساري المفعول؟
     */
    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->start_date && now()->lt($this->start_date)) {
            return false;
        }

        if ($this->end_date && now()->gt($this->end_date)) {
            return false;
        }

        return true;
    }

    /**
     * حساب قيمة الخصم على سعر معين
     */
    public function calculateDiscount(float $price, int $quantity = 1): float
    {
        return match ($this->discount_type) {
            'percentage' => min(
                $price * $this->value / 100,
                $this->max_discount_amount ?? PHP_FLOAT_MAX
            ),
            'fixed_amount' => min(
                $this->value,
                $this->max_discount_amount ?? $this->value
            ),
            'price_override' => max(0, $price - $this->value),
            'buy_x_get_y' => $this->calculateBuyXGetY($price, $quantity),
            default => 0,
        };
    }

    public function calculateLineDiscount(float $unitPrice, int $quantity = 1, ?float $invoiceSubtotal = null): float
    {
        $quantity = max(1, $quantity);
        $strategy = $this->apply_strategy ?: 'per_quantity';

        if ($strategy === 'per_invoice') {
            $base = $invoiceSubtotal ?? ($unitPrice * $quantity);
            return $this->calculateDiscountForBase($base);
        }

        $unitDiscount = $this->calculateDiscount($unitPrice, $quantity);

        return match ($strategy) {
            'per_line', 'once' => $unitDiscount,
            default => $unitDiscount * $quantity,
        };
    }

    protected function calculateDiscountForBase(float $base): float
    {
        return match ($this->discount_type) {
            'percentage' => min($base * $this->value / 100, $this->max_discount_amount ?? PHP_FLOAT_MAX),
            'fixed_amount' => min($this->value, $base, $this->max_discount_amount ?? $this->value),
            'price_override' => max(0, $base - $this->value),
            default => 0,
        };
    }

    /**
     * حساب السعر النهائي بعد الخصم
     */
    public function calculateFinalPrice(float $price, int $quantity = 1): float
    {
        if ($this->discount_type === 'price_override') {
            return max(0, $this->value);
        }

        $discountAmount = $this->calculateDiscount($price, $quantity);
        return max(0, $price - $discountAmount);
    }

    /**
     * حساب خصم Buy X Get Y
     */
    protected function calculateBuyXGetY(float $price, int $quantity): float
    {
        if (!$this->buy_quantity || !$this->get_quantity) {
            return 0;
        }

        $qualifyingSets = intdiv($quantity, $this->buy_quantity + $this->get_quantity);
        $freeItems = $qualifyingSets * $this->get_quantity;

        if ($this->get_discount_percent) {
            return $freeItems * $price * ($this->get_discount_percent / 100);
        }

        return $freeItems * $price;
    }
}
