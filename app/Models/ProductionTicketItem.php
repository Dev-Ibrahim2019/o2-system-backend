<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ربط بين تذكرة القسم وبند الطلب — يسمح بتتبع حالة التحضير لكل صنف في قسمه.
 */
class ProductionTicketItem extends Model
{
    protected $fillable = [
        'production_ticket_id',
        'order_item_id',
        'quantity',
        'status',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ProductionTicket::class, 'production_ticket_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
