<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سجل استخدام الخصم — للتدقيق والتقارير
 */
class DiscountUsageLog extends Model
{
    protected $fillable = [
        'discount_id',
        'invoice_id',
        'invoice_item_id',
        'order_id',
        'entity_type',
        'entity_id',
        'original_price',
        'discount_amount',
        'final_price',
        'discount_percent',
        'applied_by',
        'branch_id',
    ];

    protected $casts = [
        'original_price' => 'decimal:3',
        'discount_amount' => 'decimal:3',
        'final_price' => 'decimal:3',
        'discount_percent' => 'decimal:2',
    ];

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
