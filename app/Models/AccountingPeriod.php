<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingPeriod extends Model
{
    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'status',
        'closed_by',
        'closed_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'closed_at'  => 'datetime',
    ];

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public static function findForDate(\Carbon\Carbon $date): ?self
    {
        return static::where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->where('status', 'open')
            ->first();
    }
}
