<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Transaction extends Model
{
    protected $fillable = [
        'transaction_number',
        'date',
        'reference',
        'type',
        'status',
        'description',
        'branch_id',
        'user_id',
        'source_type', // ✅ 'App\Models\Order' | 'App\Models\Employee' | null
        'source_id',   // ✅ id الـ model المصدر
        'posted_at',
        'notes',
    ];

    protected $casts = [
        'date'      => 'date',
        'posted_at' => 'datetime',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * ✅ Polymorphic — المصدر الذي أنشأ هذا القيد
     *
     * المصادر المدعومة حالياً:
     *   App\Models\Order    → قيد مبيعات ناتج عن طلب
     *   App\Models\Employee → قيد راتب ناتج عن موظف
     *   null                → قيد يومية عادي بدون مصدر
     *
     * كيفية الاستخدام:
     *   // إنشاء قيد مرتبط بطلب
     *   Transaction::create([
     *       'source_type' => Order::class,
     *       'source_id'   => $order->id,
     *       ...
     *   ]);
     *
     *   // قراءة المصدر
     *   $transaction->source        // → Order instance
     *   $transaction->source_label  // → 'Order'
     *
     *   // جلب كل قيود طلب معين
     *   Transaction::forSource($order)->get();
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopePosted($query)
    {
        return $query->where('status', 'posted');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByDateRange($query, string $from, string $to)
    {
        return $query->whereBetween('date', [$from, $to]);
    }

    /**
     * فلترة بالمصدر — يجلب كل القيود المرتبطة بـ model معين
     *
     * مثال:
     *   Transaction::forSource($order)->get();
     *   Transaction::forSource($employee)->posted()->get();
     */
    public function scopeForSource($query, Model $source)
    {
        return $query
            ->where('source_type', get_class($source))
            ->where('source_id',   $source->id);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function getTotalDebitAttribute(): float
    {
        return (float) $this->entries()->sum('debit');
    }

    public function getTotalCreditAttribute(): float
    {
        return (float) $this->entries()->sum('credit');
    }

    public function isBalanced(): bool
    {
        return abs($this->total_debit - $this->total_credit) < 0.001;
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * اسم المصدر القصير للعرض (بدون namespace كامل)
     * 'App\Models\Order'    → 'Order'
     * 'App\Models\Employee' → 'Employee'
     * null                  → null
     */
    public function getSourceLabelAttribute(): ?string
    {
        return $this->source_type ? class_basename($this->source_type) : null;
    }

    /**
     * توليد رقم قيد تلقائي: JV-YYYYMMDD-XXXX
     */
    public static function generateNumber(): string
    {
        $prefix = 'JV-' . now()->format('Ymd') . '-';
        $last   = static::where('transaction_number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('transaction_number');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    public function post(): void
    {
        $this->update([
            'status'    => 'posted',
            'posted_at' => now(),
        ]);
    }

    public function cancel(): void
    {
        $this->update(['status' => 'cancelled']);
    }
}
