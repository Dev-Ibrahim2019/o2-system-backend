<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** توزيع المستخدم لسطر مسودة على الدفعات (بالوحدة الأساسية) — يصبح تخصيصاً فعلياً عند الترحيل. */
class StockDraftAllocation extends Model
{
    public const DOCUMENT_LINE = 'stock_document_line';

    public const COUNT_LINE = 'stock_count_line';

    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'decimal:6'];

    public function layer(): BelongsTo
    {
        return $this->belongsTo(StockLayer::class, 'layer_id');
    }
}
