<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سطر مستند مخزني بالوحدة المُدخلة. تعديله عبر StockDocumentService فقط وهو مسودة. */
class StockDocumentLine extends Model
{
    protected $fillable = [
        'line_no', 'stock_item_id', 'unit_id', 'quantity', 'unit_cost', 'waste_reason', 'notes',
        'batch_id', 'direction', 'cartons', 'production_date', 'expiry_date', 'value_ratio', 'is_prime', 'sale_price',
    ];

    protected $casts = [
        'quantity' => 'decimal:6',
        'unit_cost' => 'decimal:6',
        'posted_cost' => 'decimal:6',
        'cartons' => 'decimal:2',
        'value_ratio' => 'decimal:4',
        'sale_price' => 'decimal:6',
        'is_prime' => 'boolean',
        'production_date' => 'date:Y-m-d',
        'expiry_date' => 'date:Y-m-d',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class);
    }

    public function createdBatch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'created_batch_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class, 'stock_document_id');
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class);
    }
}
