<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VoucherAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'voucher_id',
        'invoice_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
    ];

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
