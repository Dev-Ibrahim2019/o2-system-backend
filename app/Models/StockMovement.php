<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * حركة مخزنية مرحّلة. تُنشأ حصراً عبر App\Services\Inventory\StockLedger.
 *
 * لا تُعدَّل ولا تُحذف (القاعدة 2) — محمية بـ Trigger في القاعدة، وهنا أيضاً حتى يفشل
 * الخطأ مبكراً برسالة واضحة. التصحيح بحركة عكسية فقط.
 * لا تستخدم Auditable: الحركة نفسها هي السجل (من أنشأها ومتى ولماذا).
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    /**
     * أنواع الحركات واتجاه كل نوع. الحركة العكسية تحمل نوع الأصلية واتجاهاً معاكساً.
     * الأنواع غير "opening" تُستخدم من المراحل التالية (المشتريات، البيع، الإنتاج، العمليات).
     */
    public const TYPES = [
        'opening' => ['label' => 'رصيد أول المدة', 'direction' => 'in'],
        'purchase' => ['label' => 'استلام مشتريات', 'direction' => 'in'],
        'sale' => ['label' => 'استهلاك بيع', 'direction' => 'out'],
        'production_out' => ['label' => 'صرف للإنتاج', 'direction' => 'out'],
        'production_in' => ['label' => 'ناتج إنتاج', 'direction' => 'in'],
        'transfer_out' => ['label' => 'تحويل صادر', 'direction' => 'out'],
        'transfer_in' => ['label' => 'تحويل وارد', 'direction' => 'in'],
        'adjustment' => ['label' => 'تسوية جرد', 'direction' => 'both'],
        'waste' => ['label' => 'هالك', 'direction' => 'out'],
        'purchase_return' => ['label' => 'مرتجع مشتريات', 'direction' => 'out'],
        // قيمة فقط (كمية صفر): محاذاة ترحيل الدفعات / تصحيح تكلفة بلا حركة أصلية. لا تُسجَّل من StockMovementLine.
        'revaluation' => ['label' => 'إعادة تقييم', 'direction' => 'value'],
    ];

    /** الأنواع التي يُسمح بعكسها يدوياً من الواجهة. بقية الأنواع تُعكس من مستنداتها (إلغاء طلب، مرتجع...). */
    public const MANUALLY_REVERSIBLE = ['opening'];

    protected $guarded = ['id'];

    protected $casts = [
        'occurred_at' => 'datetime',
        'accounting_day' => 'date:Y-m-d',
        'created_at' => 'datetime',
        'accounting_posted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (StockMovement $m) {
            if (array_keys($m->getDirty()) !== ['accounting_posted_at']) {
                throw new LogicException('الحركة المرحّلة لا تُعدَّل. صحّحها بحركة عكسية.');
            }
        });
        static::deleting(fn () => throw new LogicException('الحركة المرحّلة لا تُحذف. صحّحها بحركة عكسية.'));
    }

    public static function typeLabel(string $type): string
    {
        return self::TYPES[$type]['label'] ?? $type;
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function enteredUnit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class, 'entered_unit_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }
}
