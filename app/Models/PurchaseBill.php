<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PurchaseBill extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'bill_number', 'supplier_id', 'currency', 'bill_date', 'due_date',
        'status', 'subtotal', 'tax_total', 'discount', 'total', 'paid_amount',
        'reference', 'notes', 'branch_id', 'journal_entry_id',
        'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:4',
        'tax_total' => 'decimal:4',
        'discount' => 'decimal:4',
        'total' => 'decimal:4',
        'paid_amount' => 'decimal:4',
        'approved_at' => 'datetime',
    ];

    public static function boot()
    {
        parent::boot();
        static::creating(function ($bill) {
            if (empty($bill->bill_number)) {
                $bill->bill_number = static::generateBillNumber();
            }
        });
    }

    public static function generateBillNumber(): string
    {
        $year = date('Y');
        $last = static::where('bill_number', 'like', "PB-{$year}-%")
            ->orderByRaw("CAST(SUBSTRING(bill_number, 9) AS UNSIGNED) DESC")
            ->first();
        if ($last) {
            $num = intval(substr($last->bill_number, 9)) + 1;
        } else {
            $num = 1;
        }
        return sprintf("PB-%s-%06d", $year, $num);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseBillItem::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PurchaseBillAttachment::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
