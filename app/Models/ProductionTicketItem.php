<?php
// app/Models/ProductionTicketItem.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionTicketItem extends Model
{
    protected $fillable = [
        'ticket_id',
        'order_item_id',
        'item_name',
        'item_name_ar',
        'quantity',
        'notes',
        'status',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ProductionTicket::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
