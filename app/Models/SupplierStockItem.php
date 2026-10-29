<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ربط صنف مخزني بمورّد: سعر المورّد للوحدة، وكوده، وهل هو المورّد المفضّل للصنف (واحد فقط). */
class SupplierStockItem extends Model
{
    use Auditable;

    protected $table = 'supplier_stock_item';

    protected $fillable = ['supplier_id', 'stock_item_id', 'unit_id', 'price', 'supplier_item_code', 'is_preferred'];

    protected $casts = [
        'price' => 'decimal:6',
        'is_preferred' => 'boolean',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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
