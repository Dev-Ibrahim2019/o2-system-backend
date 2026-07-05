<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SalesInvoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'number', 'uuid', 'reference_number',
        'type', 'status', 'tax_treatment',
        'customer_id', 'customer_name', 'customer_phone', 'customer_vat_number',
        'invoice_date', 'due_date', 'supply_date',
        'currency', 'exchange_rate',
        'subtotal', 'discount_total', 'tax_total', 'total', 'paid_amount', 'remaining_amount',
        'branch_id',
        'source', 'pos_register_id', 'batch_date',
        'zatca_uuid', 'zatca_hash', 'zatca_qr_data', 'zatca_sent_at', 'zatca_status',
        'notes',
        'approved_by', 'approved_at',
        'transaction_id',
    ];

    protected $casts = [
        'invoice_date' => 'datetime',
        'supply_date' => 'date',
        'due_date' => 'date',
        'exchange_rate' => 'decimal:6',
        'subtotal' => 'decimal:4',
        'discount_total' => 'decimal:4',
        'tax_total' => 'decimal:4',
        'total' => 'decimal:4',
        'paid_amount' => 'decimal:4',
        'remaining_amount' => 'decimal:4',
        'approved_at' => 'datetime',
        'zatca_sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (SalesInvoice $invoice) {
            if (! $invoice->uuid) {
                $invoice->uuid = Str::uuid()->toString();
            }
            if (! $invoice->number) {
                $invoice->number = static::generateNumber();
            }
        });
    }

    // ═══════════════════════════════════════════════════════
    //  Relationships
    // ═══════════════════════════════════════════════════════

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesInvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalesInvoicePayment::class);
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    // ═══════════════════════════════════════════════════════
    //  Scopes
    // ═══════════════════════════════════════════════════════

    public function scopeForBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeWithStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'awaiting_payment')
            ->where('due_date', '<', now());
    }

    // ═══════════════════════════════════════════════════════
    //  Helpers
    // ═══════════════════════════════════════════════════════

    public static function generateNumber(): string
    {
        $prefix = 'SINV-' . now()->format('Ymd') . '-';
        $last = static::where('number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('number');

        $seq = $last ? (int) substr($last, -4) + 1 : 1;

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public function recalculateTotals(): void
    {
        $this->subtotal = $this->items()->sum('total_before_tax');
        $this->discount_total = $this->items()->sum('discount');
        $this->tax_total = $this->items()->sum('tax_amount');
        $this->total = $this->items()->sum('total');
        $this->paid_amount = $this->payments()->sum('amount');
        $this->remaining_amount = max(0, $this->total - $this->paid_amount);
        $this->save();
    }

    public function approve(User $user): void
    {
        $this->update([
            'status' => 'awaiting_payment',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);
    }

    public function markPaid(): void
    {
        $this->update([
            'status' => 'paid',
            'paid_amount' => $this->total,
            'remaining_amount' => 0,
        ]);
    }

    public function cancel(): void
    {
        $this->update(['status' => 'cancelled']);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isApproved(): bool
    {
        return in_array($this->status, ['awaiting_approval', 'awaiting_payment']);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
