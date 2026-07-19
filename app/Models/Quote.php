<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Quote extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'quote_number',
        'share_token',
        'status',
        'client_id',
        'client_name',
        'client_phone',
        'client_email',
        'issuer_id',
        'branch_id',
        'issue_date',
        'expiry_date',
        'currency',
        'subtotal',
        'tax_total',
        'discount_total',
        'total',
        'notes',
        'terms',
        'converted_invoice_id',
    ];

    protected $casts = [
        'issue_date'   => 'date',
        'expiry_date'  => 'date',
        'subtotal'     => 'decimal:4',
        'tax_total'    => 'decimal:4',
        'discount_total' => 'decimal:4',
        'total'        => 'decimal:4',
    ];

    // ── Relations ──

    public function items()
    {
        return $this->hasMany(QuoteItem::class, 'quote_id')->orderBy('sort_order');
    }

    public function client()
    {
        return $this->belongsTo(Customer::class, 'client_id');
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issuer_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function convertedInvoice()
    {
        return $this->belongsTo(Invoice::class, 'converted_invoice_id');
    }

    // ── Boot ──

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Quote $quote) {
            if (empty($quote->share_token)) {
                $quote->share_token = 'quo_' . Str::random(32);
            }
        });
    }
}
