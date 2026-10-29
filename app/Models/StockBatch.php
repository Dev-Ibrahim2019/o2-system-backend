<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * دفعة من صنف: تُنشأ عند الاستلام (للأصناف التي تتتبع الصلاحية)، أو كناتج تفريك/إنتاج/سيخ شاورما.
 * رصيدها في كل مخزن في stock_batch_balances ويحدّثه StockLedger وحده.
 */
class StockBatch extends Model
{
    protected $fillable = [
        'stock_item_id', 'code', 'sequence', 'source', 'stock_document_id', 'supplier_id', 'unit_cost', 'quantity',
        'cartons', 'production_date', 'expiry_date', 'notes', 'created_by',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:6',
        'quantity' => 'decimal:6',
        'cartons' => 'decimal:2',
        'production_date' => 'date:Y-m-d',
        'expiry_date' => 'date:Y-m-d',
    ];

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class, 'stock_document_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(StockBatchBalance::class, 'batch_id');
    }
}
