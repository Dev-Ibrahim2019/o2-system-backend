<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'item_id',
        'item_name',
        'quantity',
        'price',
        'original_price',
        'subtotal',
        'total',
        'discount_amount',
        'discount_percent',
        'discount_id',
        'discount_apply_strategy',
        'tax_rate',
        'tax_amount',
        'final_price',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'price' => 'decimal:3',
        'original_price' => 'decimal:3',
        'subtotal' => 'decimal:3',
        'total' => 'decimal:3',
        'discount_amount' => 'decimal:3',
        'discount_percent' => 'decimal:2',
        'final_price' => 'decimal:3',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:3',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function discount()
    {
        return $this->belongsTo(Discount::class);
    }
}
