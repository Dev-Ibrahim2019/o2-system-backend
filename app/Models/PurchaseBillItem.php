<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseBillItem extends Model
{
    protected $fillable = [
        'purchase_bill_id', 'product_id', 'description', 'quantity', 'unit_price',
        'tax_rate', 'tax_amount', 'discount', 'total_before_tax', 'line_total', 'account_id',
        'line_no', 'line_kind', 'stock_item_id', 'unit_id', 'discount_pct', 'allocated_discount',
        'stock_unit_cost',
    ];

    protected $casts = [
        'discount_pct' => 'decimal:4',
        'allocated_discount' => 'decimal:4',
        'stock_unit_cost' => 'decimal:6',
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:4',
        'discount' => 'decimal:4',
        'total_before_tax' => 'decimal:4',
        'line_total' => 'decimal:4',
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'product_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class, 'unit_id');
    }
}
