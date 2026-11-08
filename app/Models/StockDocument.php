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

    public const TYPES = [
        self::TYPE_RECEIPT => ['label' => 'استلام مشتريات', 'prefix' => 'GRN'],
        self::TYPE_TRANSFER => ['label' => 'صرفية أصناف', 'prefix' => 'TRF'],
        self::TYPE_WASTE => ['label' => 'هالك', 'prefix' => 'WST'],
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
        'document_date', 'notes', 'created_by', 'destination', 'delivery_status', 'reference_no',
        'document_time', 'quantities_only',
    ];

    protected $casts = [
        'document_date' => 'date:Y-m-d',
        'total_cost' => 'decimal:6',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
        'received_at' => 'datetime',
        'quantities_only' => 'boolean',
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

    /** تأكيدات الاستلام لكل مخزن وجهة (2027_03_10_000002). */
    public function receipts(): HasMany
    {
        return $this->hasMany(StockDocumentReceipt::class);
    }

    /**
     * مخازن الوجهة الفعلية للصرفية: وجهة كل سطر، وإلا مخزن الرأس (الصرفيات القديمة لها وجهة واحدة).
     *
     * @return array<int>
     */
    public function destinationIds(): array
    {
        if ($this->type !== self::TYPE_TRANSFER) {
            return [];
        }
        $ids = $this->lines->map(fn ($l) => (int) ($l->to_warehouse_id ?: $this->to_warehouse_id))->filter()->unique()->values()->all();

        return $ids !== [] ? $ids : array_values(array_filter([(int) $this->to_warehouse_id]));
    }

    /** مكتمل الاستلام (كل الوجهات). */
    public function isReceived(): bool
    {
        return $this->received_at !== null;
    }

    /** أُكّد استلامه في وجهة واحدة على الأقل (يكفي لاشتراط صلاحية عكس المستلَم). */
    public function hasAnyReceipt(): bool
    {
        return $this->isReceived() || $this->receipts()->exists();
    }

    /** received | partial | pending — للصرفية المرحّلة فقط، وإلا null. */
    public function receiptStatus(): ?string
    {
        if ($this->type !== self::TYPE_TRANSFER || $this->status === self::STATUS_DRAFT) {
            return null;
        }
        if ($this->isReceived()) {
            return 'received';
        }
        $count = $this->relationLoaded('receipts') ? $this->receipts->count() : $this->receipts()->count();
        if ($count > 0) {
            return 'partial';
        }

        // المعكوس بلا أي استلام: لا ينتظر استلاماً.
        return $this->status === self::STATUS_REVERSED ? null : 'pending';
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
