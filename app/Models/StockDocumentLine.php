<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سطر مستند مخزني بالوحدة المُدخلة. تعديله عبر StockDocumentService فقط وهو مسودة. */
class StockDocumentLine extends Model
{
    protected $fillable = [
        'line_no', 'stock_item_id', 'unit_id', 'quantity', 'unit_cost', 'waste_reason', 'notes', 'cartons',
    ];

    protected $casts = [
        'quantity' => 'decimal:6',
        'unit_cost' => 'decimal:6',
        'posted_cost' => 'decimal:6',
        'cartons' => 'decimal:2',
    ];

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
