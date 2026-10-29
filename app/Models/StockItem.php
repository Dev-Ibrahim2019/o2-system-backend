<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * صنف مخزني: مادة خام، منتج وسيط، منتج نهائي (مرتبط بصنف O2 المباع)،
 * أو صنف لا يخضع للمخزون (خدمة مثل أجرة التوصيل).
 *
 * سعر البيع لا يُخزَّن هنا — يُقرأ من صنف O2 (branch_item) عند الحاجة.
 */
class StockItem extends Model
{
    use Auditable;

    public const TYPE_RAW = 'raw';
    public const TYPE_INTERMEDIATE = 'intermediate';
    public const TYPE_FINISHED = 'finished';
    public const TYPE_NON_STOCK = 'non_stock';

    public const TYPES = [
        self::TYPE_RAW,
        self::TYPE_INTERMEDIATE,
        self::TYPE_FINISHED,
        self::TYPE_NON_STOCK,
    ];

    /** الأنواع التي تمثّل صنفاً مباعاً في O2 ويجب ربطها بـ items. */
    public const SOLD_TYPES = [self::TYPE_FINISHED, self::TYPE_NON_STOCK];

    protected $fillable = [
        'item_id',
        'type',
        'name',
        'name_en',
        'code',
        'base_unit_id',
        'purchase_unit_id',
        'default_warehouse_id',
        'is_stored',
        'barcode',
        'supplier_code',
        'safety_stock',
        'tracks_expiry',
        'is_active',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'is_stored' => 'boolean',
        'tracks_expiry' => 'boolean',
        'is_active' => 'boolean',
        'safety_stock' => 'decimal:6',
        'last_cost' => 'decimal:6',
    ];

    public function purchaseUnit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class, 'purchase_unit_id');
    }

    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }

    /** موردو الصنف وأسعارهم (last_cost لا يُعبّأ من الواجهة؛ يحدّثه ترحيل الاستلام). */
    public function supplierLinks(): HasMany
    {
        return $this->hasMany(SupplierStockItem::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class, 'base_unit_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(StockItemUnit::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(StockCategory::class, 'stock_category_item');
    }
}
