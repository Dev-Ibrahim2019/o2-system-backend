<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * دفعة مرتبطة بالفاتورة الرسمية — عند اكتمال السداد يصبح order.status = paid
 */
class Payment extends Model
{
    protected $fillable = [
        'invoice_id',
        'number',
        'method',
        'amount',
        'paid_at',
        'notes',
        'branch_id',
        'user_id',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'amount' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function generateNumber(): string
    {
        $prefix = 'PAY-'.now()->format('Ymd').'-';
        $last = static::where('number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('number');

        $seq = $last ? (int) substr($last, -4) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
