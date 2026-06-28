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
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
