<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
   protected $fillable = [
        'number',
        'order_id',
        'customer_id',
        'branch_id',
        'status',
        'payment_method',
        'subtotal',
        'discount',
        'tax',
        'total',
        'paid_amount',
        'remaining_amount',
        'invoice_date',
        'notes',
    ];

    protected $casts = [
        'invoice_date' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
