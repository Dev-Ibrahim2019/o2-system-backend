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
        // فاتورة المشتريات الموحّدة (2027_03_09_000001)
        'is_inventory', 'warehouse_id', 'supplier_invoice_no', 'exchange_rate', 'payment_mode',
        'paid_on_post', 'cash_account_id', 'tax_recoverable',
    ];

    protected $casts = [
        'subtotal' => 'decimal:4',
        'tax_total' => 'decimal:4',
        'discount' => 'decimal:4',
        'total' => 'decimal:4',
        'paid_amount' => 'decimal:4',
        'paid_on_post' => 'decimal:4',
        'exchange_rate' => 'decimal:6',
        'is_inventory' => 'boolean',
        'tax_recoverable' => 'boolean',
        'bill_date' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    /** حالات الفاتورة المرحّلة (لها استلام وقيد). "متأخرة" تُحسب من الاستحقاق ولا تُخزَّن. */
    public const POSTED_STATUSES = ['unpaid', 'partially_paid', 'paid', 'overdue'];

    public static function boot()
    {
        parent::boot();
        static::creating(function ($bill) {
            if (empty($bill->bill_number)) {
                $bill->bill_number = static::generateBillNumber();
            }
        });
    }

    /** PB-YYYY-NNNNNN للفواتير القديمة، PI-YYYY-NNNNNN لفاتورة المشتريات الموحّدة. */
    public static function generateBillNumber(string $prefix = 'PB'): string
    {
        $year = date('Y');
        $last = static::withTrashed()->where('bill_number', 'like', "{$prefix}-{$year}-%")
            ->orderByRaw("CAST(SUBSTRING(bill_number, 9) AS UNSIGNED) DESC")
            ->first();
        if ($last) {
            $num = intval(substr($last->bill_number, 9)) + 1;
        } else {
            $num = 1;
        }
        return sprintf("%s-%s-%06d", $prefix, $year, $num);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    /** الاستلام المخزني الذي أنشأه ترحيل الفاتورة. */
    public function stockDocument(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class);
    }

    /** قيد الفاتورة في الدفتر العام (مخزون/مشتريات + ضريبة مدخلات / ذمم الموردين). */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'journal_entry_id');
    }

    /** سندات الصرف المربوطة (المدفوع عند الترحيل والتسديدات اللاحقة). */
    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function isPosted(): bool
    {
        return in_array($this->status, self::POSTED_STATUSES, true);
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
