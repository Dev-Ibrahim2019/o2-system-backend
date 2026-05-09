<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'date',
        'reference',
        'type',
        'description',
        'branch_id',
        'user_id'
    ];

    protected $dates = ['date'];

    // 🔹 Entries
    public function entries()
    {
        return $this->hasMany(Entry::class);
    }

    // 🔥 مجموع المدين
    public function getTotalDebitAttribute()
    {
        return $this->entries()->sum('debit');
    }

    // 🔥 مجموع الدائن
    public function getTotalCreditAttribute()
    {
        return $this->entries()->sum('credit');
    }

    // 🔥 تحقق التوازن
    public function isBalanced()
    {
        return $this->total_debit == $this->total_credit;
    }

}
