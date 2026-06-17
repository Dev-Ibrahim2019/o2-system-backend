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
        'item_id',
        'department_id',
        'item_name',
        'item_name_ar',
        'price',
        'quantity',
        'total',
        'status',
        'notes',
        'sent_to_kitchen_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'quantity' => 'decimal:2',
        'total' => 'decimal:2',
        'sent_to_kitchen_at' => 'datetime',
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

    public function ticketItem(): HasOne
    {
        return $this->hasOne(ProductionTicketItem::class);
    }
}
