<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * بند الطلب — يحفظ الاسم والسعر وقت الإضافة (مستقل عن تغيّر سعر القائمة لاحقاً).
 * department_id يأتي من items.department_id ويُستخدم عند تقسيم الطلب للأقسام.
 */
class OrderItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'created_by',
        'item_id',
        'department_id',
        'item_name',
        'item_name_ar',
        'price',
        'original_price',
        'final_price',
        'discount_amount',
        'discount_percent',
        'discount_id',
        'discount_apply_strategy',
        'tax_rate',
        'tax_amount',
        'quantity',
        'total',
        'status',
        'notes',
        'sent_to_kitchen_at',
        'is_printed_direct',
        'is_takeaway',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'quantity' => 'decimal:2',
        'total' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'sent_to_kitchen_at' => 'datetime',
        'is_printed_direct' => 'boolean',
        'is_takeaway' => 'boolean',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function ticketItem(): HasOne
    {
        return $this->hasOne(ProductionTicketItem::class);
    }
}
