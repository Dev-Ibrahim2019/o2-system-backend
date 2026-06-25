<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * الفاتورة الرسمية — تُنشأ من الطلب بعد تقسيمه للأقسام (تذاكر).
 * الدفع يتم عبر payments ولا يُربط مباشرة بالطلب.
 *
 * تحتوي الفاتورة على جزئين:
 *   1. تفاصيل نقطة البيع (POS): pos_register_id, pos_code, pos_name, branch, user
 *   2. تفاصيل الفاتورة: number, time, date, currency, status, account_number
 *   3. تفاصيل الفتح والإغلاق: opened_by/at, closed_by/at
 */
class Invoice extends Model
{
    protected $fillable = [
        'number',
        'order_id',
        'customer_id',
        'branch_id',
        'status',
        'payment_method',
        'subtotal',
        'discount',
        'total',
        'invoice_date',
        'notes',
        // ── حقول POS الجديدة ──
        'pos_register_id',
        'pos_code',
        'pos_name',
        'opened_by',
        'opened_at',
        'closed_by',
        'closed_at',
        'currency',
        'account_number',
    ];

    protected $casts = [
        'invoice_date' => 'datetime',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    // ═══════════════════════════════════════════════════════
    //  العلاقات (Relationships)
    // ═══════════════════════════════════════════════════════

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** نقطة البيع التي أُنشئت منها الفاتورة */
    public function posRegister(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class, 'pos_register_id');
    }

    /** المستخدم الذي فتح الفاتورة */
    public function openedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /** المستخدم الذي أغلق الفاتورة */
    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** القيد المحاسبي المرتبط بالطلب التابع لهذه الفاتورة */
    public function journalEntry()
    {
        if (! $this->order_id) {
            return null;
        }

        return Transaction::where('source_type', Order::class)
            ->where('source_id', $this->order_id)
            ->where('type', 'sales')
            ->first();
    }

    public function paidAmount(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function remainingAmount(): float
    {
        return max(0, (float) $this->total - $this->paidAmount());
    }

    public static function generateNumber(): string
    {
        $prefix = 'INV-'.now()->format('Ymd').'-';
        $last = static::where('number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('number');

        $seq = $last ? (int) substr($last, -4) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
