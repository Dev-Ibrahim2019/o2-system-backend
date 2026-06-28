<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * الفاتورة الرسمية — تُنشأ من الطلب بعد تقسيمه للأقسام (تذاكر).
 * الدفع يتم عبر payments ولا يُربط مباشرة بالطلب.
 */
class Invoice extends Model
{
    protected $fillable = [
        'number',
        'type',
        'order_id',
        'customer_id',
        'entity_type',
        'entity_id',
        'branch_id',
        'status',
        'currency',
        'payment_method',
        'subtotal',
        'discount',
        'tax_total',
        'total',
        'paid_amount',
        'remaining_amount',
        'invoice_date',
        'due_date',
        'delivery_date',
        'expected_payment_date',
        'notes',
    ];

    protected $casts = [
        'invoice_date' => 'datetime',
        'due_date' => 'datetime',
        'delivery_date' => 'datetime',
        'expected_payment_date' => 'datetime',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
    ];

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
