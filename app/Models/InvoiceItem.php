<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'item_id',
        'item_name',
        'description',
        'quantity',
        'price',
        'unit_price',
        'discount',
        'total_before_tax',
        'tax_rate',
        'tax_amount',
        'total',
        'account_id',
        'branch_id',
        'original_price',
        'subtotal',
        'total',
        'discount_amount',
        'discount_percent',
        'discount_id',
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