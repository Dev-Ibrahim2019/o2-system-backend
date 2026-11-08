<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تأكيد استلام صرفية في مخزن وجهة واحد (توثيق فقط — الأرصدة تحرّكت عند الترحيل).
 * يُنشأ عبر StockDocumentService::confirmReceipt ولا يُلغى؛ تصحيح المستلَم بعكس الصرفية.
 */
class StockDocumentReceipt extends Model
{
    public $timestamps = false;

    protected $fillable = ['stock_document_id', 'warehouse_id', 'received_by', 'received_at'];

    protected $casts = ['received_at' => 'datetime'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class, 'stock_document_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
