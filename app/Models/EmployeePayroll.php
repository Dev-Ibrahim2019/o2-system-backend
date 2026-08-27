<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePayroll extends Model
{
    protected $fillable = [
        'employee_id', 'period_month', 'period_year', 'salary_type',
        'worked_hours', 'worked_days', 'base_salary', 'allowances', 'deductions',
        'advance_deduction', 'gross_amount', 'payable_amount', 'net_amount',
        'cash_account_id', 'payment_date', 'status', 'notes',
        'accrual_transaction_id', 'payment_transaction_id', 'created_by',
    ];

    protected $casts = [
        'period_month' => 'integer',
        'period_year' => 'integer',
        'worked_hours' => 'decimal:2',
        'worked_days' => 'integer',
        'base_salary' => 'decimal:2',
        'allowances' => 'decimal:2',
        'deductions' => 'decimal:2',
        'advance_deduction' => 'decimal:2',
        'gross_amount' => 'decimal:2',
        'payable_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'payment_date' => 'date',
    ];

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function cashAccount(): BelongsTo { return $this->belongsTo(Account::class, 'cash_account_id'); }
    public function accrualTransaction(): BelongsTo { return $this->belongsTo(Transaction::class, 'accrual_transaction_id'); }
    public function paymentTransaction(): BelongsTo { return $this->belongsTo(Transaction::class, 'payment_transaction_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
