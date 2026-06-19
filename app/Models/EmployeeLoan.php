<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class EmployeeLoan extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'amount',
        'date_granted',
        'repayment_date',
        'amount_paid',
        'status',
        'notes',
        'transaction_id',
    ];

    protected $casts = [
        'date_granted' => 'date',
        'repayment_date' => 'date',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function getRemainingAmountAttribute()
    {
        return $this->amount - $this->amount_paid;
    }
}