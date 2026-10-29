<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رصيد صنف في مخزن ومتوسط تكلفته المرجّح — نتيجة الحركات لا رقم يُكتب (القاعدة 1).
 * للقراءة فقط من التطبيق؛ يحدّثه StockLedger وحده، والـ Trigger يرفض أي تعديل آخر.
 */
class StockBalance extends Model
{
    public const CREATED_AT = null;

    protected $guarded = ['*'];

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
