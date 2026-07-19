<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuoteItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'quote_id',
        'item_id',
        'description',
        'sort_order',
        'quantity',
        'unit_price',
        'discount_percent',
        'tax_rate',
        'subtotal',
        'tax_amount',
        'total',
    ];

    protected $casts = [
        'quantity'         => 'decimal:2',
        'unit_price'       => 'decimal:4',
        'discount_percent' => 'decimal:2',
        'tax_rate'         => 'decimal:2',
        'subtotal'         => 'decimal:4',
        'tax_amount'       => 'decimal:4',
        'total'            => 'decimal:4',
    ];

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
