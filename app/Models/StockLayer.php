<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * دفعة تكلفة: كمية دخلت مخزناً بسعر واحد (سطر فاتورة، أول مدة، تحويل، زيادة جرد).
 * تُنشأ وتُستهلك عبر StockLayerService داخل معاملة StockLedger فقط؛ لا تُحذف (Trigger).
 */
class StockLayer extends Model
{
    public const SOURCE_LABELS = [
        'purchase_invoice' => 'فاتورة مشتريات',
        'receipt' => 'استلام مشتريات',
        'opening_balance' => 'رصيد أول المدة',
        'transfer_in' => 'تحويل وارد',
        'adjustment_in' => 'زيادة جرد',
        'production_in' => 'ناتج إنتاج',
        'sales_return' => 'مرتجع مبيعات',
        'split' => 'تصحيح تكلفة',
    ];

    protected $guarded = ['id', 'is_open'];

    protected $casts = [
        'received_at' => 'datetime',
        'qty_in' => 'decimal:6',
        'qty_remaining' => 'decimal:6',
        'unit_cost' => 'decimal:6',
        'is_provisional' => 'boolean',
        'is_open' => 'boolean',
    ];

    public function allocations(): HasMany
    {
        return $this->hasMany(StockLayerAllocation::class, 'layer_id');
    }

    public function origin(): BelongsTo
    {
        return $this->belongsTo(self::class, 'origin_layer_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function purchaseBill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }
}
