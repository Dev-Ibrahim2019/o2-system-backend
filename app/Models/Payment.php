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
        'customer_id',
        'number',
        'method',
        'payment_method_id',
        'amount',
        'reference_number',
        'paid_at',
        'notes',
        'branch_id',
        'user_id',
        'register_type',
        'register_id',
        'entity_type',
        'entity_id',
        'subledger_type',
        'subledger_id',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'amount' => 'decimal:2',
        'entity_id' => 'integer',
        'subledger_id' => 'integer',
        'register_id' => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** الصندوق (POS أو كول سنتر) الذي حُصِّلت عليه هذه الدفعة */
    public function register(): PosRegister|CallCenterRegister|null
    {
        return match ($this->register_type) {
            'pos_register' => PosRegister::find($this->register_id),
            'call_center_register' => CallCenterRegister::find($this->register_id),
            default => null,
        };
    }

    public static function generateNumber(): string
    {
        $prefix = 'PAY-' . now()->format('Ymd') . '-';
        $last = static::where('number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('number');

        $seq = $last ? (int) substr($last, -4) + 1 : 1;

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
