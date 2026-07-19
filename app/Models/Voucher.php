<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Voucher extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'number',
        'type',
        'entity_type',
        'entity_id',
        'entity_name',
        'amount',
        'balance_before',
        'currency',
        'payment_method_id',
        'payment_method_name',
        'reference_number',
        'branch_id',
        'shift_id',
        'accounting_day_id',
        'voucher_date',
        'status',
        'notes',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'amount'       => 'decimal:4',
        'balance_before' => 'decimal:4',
        'voucher_date' => 'date',
        'approved_at'  => 'datetime',
    ];

    // ── Relations ──

    public function allocations()
    {
        return $this->hasMany(VoucherAllocation::class);
    }

    public function entity()
    {
        return $this->morphTo();
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ── Scopes ──

    public function scopeReceipt($query)
    {
        return $query->where('type', 'receipt');
    }

    public function scopePayment($query)
    {
        return $query->where('type', 'payment');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    // ── Number Generation ──

    public static function generateNumber(string $type): string
    {
        $prefix = $type === 'receipt' ? 'RC' : 'PV';
        $year = date('Y');
        $last = static::withTrashed()
            ->where('number', 'like', "{$prefix}-{$year}-%")
            ->orderByDesc('number')
            ->first();

        $seq = 1;
        if ($last && preg_match('/(\d+)$/', $last->number, $m)) {
            $seq = (int) $m[1] + 1;
        }

        return sprintf("%s-%s-%06d", $prefix, $year, $seq);
    }

    // ── Balance Calculation ──

    public static function getEntityBalance(string $entityType, int $entityId): float
    {
        // Balance = total invoices - total active voucher payments/receipts for this entity
        $totalInvoices = Invoice::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->sum('total');

        $totalPaid = static::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('status', 'active')
            ->sum('amount');

        return (float) $totalInvoices - (float) $totalPaid;
    }

    // ── Boot ──

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Voucher $voucher) {
            if (empty($voucher->number)) {
                $voucher->number = static::generateNumber($voucher->type);
            }
        });
    }
}
