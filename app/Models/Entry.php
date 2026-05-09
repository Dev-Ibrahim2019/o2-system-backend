<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Entry extends Model
{
    protected $fillable = [
        'transaction_id',
        'account_id',
        'debit',
        'credit',
        'description',
        'cost_center_id'
    ];

    // 🔹 Account
    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    // 🔹 Transaction
    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
