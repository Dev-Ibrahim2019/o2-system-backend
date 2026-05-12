<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'invoice_id',
        'number',
        'method',
        'status',
        'amount',
        'paid_at',
        'notes',
        'branch_id',
        'user_id',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
