<?php
// app/Models/OrderItem.php

namespace App\Models;

use App\Models\Department;
use App\Models\Item;
use App\Models\Order;
use App\Models\ProductionTicketItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderItem extends Model
{
    protected $fillable = [
        'order_number',
        'item_id',
        'item_name',
        'price',
        'quantity',
        'total',
        'status',
        'notes',
    ];

    protected $casts = [
        'price'  => 'decimal:3',
        'quantity'    => 'decimal:3',
        'total' => 'decimal:3',
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
