<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeWithdrawal extends Model
{
    protected $fillable = [
        'employee_id', 'amount', 'date', 'cash_account_id', 'description',
        'status', 'transaction_id', 'reversal_transaction_id', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'date',
    ];

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function cashAccount(): BelongsTo { return $this->belongsTo(Account::class, 'cash_account_id'); }
    public function transaction(): BelongsTo { return $this->belongsTo(Transaction::class); }
    public function reversalTransaction(): BelongsTo { return $this->belongsTo(Transaction::class, 'reversal_transaction_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
