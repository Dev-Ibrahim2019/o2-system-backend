<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $fillable = [
        'name',
        'code',
        'type',
        'parent_id',
        'is_active',
        'is_system'
    ];

    // 🔹 Parent
    public function parent()
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    // 🔹 Children
    public function children()
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    // 🔹 Recursive children
    public function childrenRecursive()
    {
        return $this->children()->with('childrenRecursive');
    }

    // 🔹 Entries
    public function entries()
    {
        return $this->hasMany(Entry::class);
    }

    // 🔥 حساب الرصيد
    public function getBalanceAttribute()
    {
        $debit = $this->entries()->sum('debit');
        $credit = $this->entries()->sum('credit');

        if (in_array($this->type, ['asset', 'expense'])) {
            return $debit - $credit;
        }

        return $credit - $debit;
    }
}
