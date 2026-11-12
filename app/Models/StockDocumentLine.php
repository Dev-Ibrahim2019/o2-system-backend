<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** سطر مستند مخزني بالوحدة المُدخلة. تعديله عبر StockDocumentService فقط وهو مسودة. */
class StockDocumentLine extends Model
{
    protected $fillable = [
        'line_no', 'stock_item_id', 'purchase_bill_item_id', 'to_warehouse_id', 'unit_id', 'quantity', 'unit_cost', 'waste_reason', 'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:6',
        'unit_cost' => 'decimal:6',
        'posted_cost' => 'decimal:6',
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

    /** مخزن الوجهة لهذا السطر في الصرفية (فارغ = مخزن الرأس). */
    /** توزيع المسودة على الدفعات (التحديد المعيّن) — فارغ = الأقدم أولاً عند الترحيل. */
    public function draftAllocations(): HasMany
    {
        return $this->hasMany(StockDraftAllocation::class, 'owner_id')->where('owner_type', StockDraftAllocation::DOCUMENT_LINE)->orderBy('id');
    }

    /** التخصيصات الفعلية بعد الترحيل (حركة السطر وما خرج منها من دفعات). */
    public function layerAllocations(): HasMany
    {
        return $this->hasMany(StockLayerAllocation::class, 'movement_line_id')->whereIn('kind', ['consume', 'settle'])
            ->whereIn('movement_type', ['transfer_out', 'waste', 'purchase_return'])->orderBy('id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }
}
