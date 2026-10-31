<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * مستند مخزني: استلام مشتريات، تحويل بين مخزنين، أو هالك.
 * يُدار حصراً عبر App\Services\Inventory\StockDocumentService (مسودة ← مرحّل ← معكوس).
 */
class StockDocument extends Model
{
    use Auditable;

    public const TYPE_RECEIPT = 'receipt';

    public const TYPE_TRANSFER = 'transfer';

    public const TYPE_WASTE = 'waste';

    public const TYPE_BREAKDOWN = 'breakdown';

    public const TYPE_PRODUCTION = 'production';

    public const TYPE_SKEWER = 'skewer';

    public const TYPE_SHOWROOM_SALE = 'showroom_sale';

    public const TYPES = [
        self::TYPE_RECEIPT => ['label' => 'استلام مشتريات', 'prefix' => 'GRN'],
        self::TYPE_TRANSFER => ['label' => 'صرف / تحويل مخزني', 'prefix' => 'TRF'],
        self::TYPE_WASTE => ['label' => 'إتلاف', 'prefix' => 'WST'],
        self::TYPE_BREAKDOWN => ['label' => 'تفريك', 'prefix' => 'BRK'],
        self::TYPE_PRODUCTION => ['label' => 'إنتاج مصنع', 'prefix' => 'PRD'],
        self::TYPE_SKEWER => ['label' => 'إنتاج أسياخ شاورما', 'prefix' => 'SKW'],
        self::TYPE_SHOWROOM_SALE => ['label' => 'مبيعات معرض', 'prefix' => 'SHS'],
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    /** أسباب الهالك المعتمدة (تقرير الهالك يجمّع بها). */
    public const WASTE_REASONS = [
        'expired' => 'منتهي الصلاحية',
        'damaged' => 'تالف',
        'spoiled' => 'فاسد',
        'prep_waste' => 'هدر تحضير',
        'returned_dish' => 'طبق مرتجع',
        'other' => 'أخرى',
    ];

    /** source_type في stock_movements للحركات الصادرة عن مستند. */
    public const SOURCE_TYPE = 'stock_document';

    protected $fillable = [
        'type', 'warehouse_id', 'to_warehouse_id', 'supplier_id', 'supplier_invoice_no',
        'document_date', 'notes', 'created_by', 'destination', 'delivery_status',
    ];

    protected $casts = [
        'document_date' => 'date:Y-m-d',
        'total_cost' => 'decimal:6',
        'revenue' => 'decimal:6',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(StockDocumentLine::class)->orderBy('line_no');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'source_id')->where('source_type', self::SOURCE_TYPE);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /** من أكّد الاستلام الفعلي في مخزن الوجهة (مستند الصرف). */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function isReceived(): bool
    {
        return $this->received_at !== null;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
