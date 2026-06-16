<?php

namespace App\Models;

use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Entry extends Model
{
    protected $fillable = [
        'transaction_id',
        'account_id',
        'debit',
        'credit',
        'description',
        'cost_center_id',
        'sort_order',
        // ── Subledger ──────────────────────────────────────
        // يربط القيد بكيان حقيقي بدون إنشاء حساب مستقل
        // القيمة: 'employee' | 'customer' | 'supplier' | null
        'subledger_type',
        // ID الكيان (employees.id / customers.id / suppliers.id)
        'subledger_id',
    ];

    protected $casts = [
        'debit'         => 'decimal:3',
        'credit'        => 'decimal:3',
        'sort_order'    => 'integer',
        'subledger_id'  => 'integer',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    // ── Subledger Scopes ──────────────────────────────────────────────────────

    /**
     * قيود كيان معين
     * مثال: Entry::forSubledger('employee', 33)->get()
     */
    public function scopeForSubledger($query, string $type, int $id)
    {
        return $query
            ->where('subledger_type', $type)
            ->where('subledger_id', $id);
    }

    /**
     * قيود كيان معين على حساب معين
     * مثال: سلف الموظف 33 فقط (حساب 1130)
     */
    public function scopeForSubledgerAccount($query, string $type, int $id, int $accountId)
    {
        return $query
            ->where('subledger_type', $type)
            ->where('subledger_id', $id)
            ->where('account_id', $accountId);
    }

    /**
     * قيود موظف معين (shortcut)
     */
    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->forSubledger('employee', $employeeId);
    }

    /**
     * قيود عميل معين (shortcut)
     */
    public function scopeForCustomer($query, int $customerId)
    {
        return $query->forSubledger('customer', $customerId);
    }

    /**
     * قيود مورد معين (shortcut)
     */
    public function scopeForSupplier($query, int $supplierId)
    {
        return $query->forSubledger('supplier', $supplierId);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isDebit(): bool
    {
        return $this->debit > 0;
    }

    public function isCredit(): bool
    {
        return $this->credit > 0;
    }

    public function hasSubledger(): bool
    {
        return $this->subledger_type !== null && $this->subledger_id !== null;
    }
    public function subledgerEmployee()
    {
        return $this->belongsTo(Employee::class, 'subledger_id');
    }

    public function subledgerCustomer()
    {
        return $this->belongsTo(Customer::class, 'subledger_id');
    }

    public function subledgerSupplier()
    {
        return $this->belongsTo(Supplier::class, 'subledger_id');
    }

    // ── Immutable Ledger Guard ────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::updating(function (Entry $entry) {
            if ($entry->transaction && $entry->transaction->status === 'posted') {
                throw new \RuntimeException('لا يمكن تعديل قيود مرحّلة');
            }
        });

        static::deleting(function (Entry $entry) {
            if ($entry->transaction && $entry->transaction->status === 'posted') {
                throw new \RuntimeException('لا يمكن حذف قيود مرحّلة');
            }
        });
    }
}
