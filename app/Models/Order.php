<?php
// app/Models/Order.php

namespace App\Models;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\OrderItem;
use App\Models\ProductionTicket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_number',
        'branch_id',
        'cashier_id',
        'order_type',
        'status',
        'table_number',
        'customer_name',
        'customer_phone',
        'note',
        'subtotal',
        'discount_value',
        'discount_type',
        'discount_amount',
        'total',
        'payment_method',
    ];

    protected $casts = [
        'subtotal'       => 'decimal:3',
        'discount_value' => 'decimal:3',
        'discount_amount' => 'decimal:3',
        'total'          => 'decimal:3',
    ];

    // ── Relations ────────────────────────────────────────────────────────────

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'cashier_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(ProductionTicket::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * توليد رقم طلب تلقائي: ORD-YYYYMMDD-XXXX
     */
    public static function generateOrderNumber(): string
    {
        $prefix = 'ORD-' . now()->format('Ymd') . '-';
        $last   = static::where('order_number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('order_number');

        $seq = $last ? (int) substr($last, -4) + 1 : 1;

        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
