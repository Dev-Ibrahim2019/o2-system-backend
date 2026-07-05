<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesInvoiceItem extends Model
{
    protected $fillable = [
        'sales_invoice_id',
        'item_id', 'item_name', 'description',
        'quantity', 'unit_price', 'discount', 'discount_percent',
        'tax_rate', 'tax_amount',
        'total_before_tax', 'total',
        'account_id', 'branch_id',
        'tracking_name', 'tracking_option',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'discount' => 'decimal:4',
        'discount_percent' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:4',
        'total_before_tax' => 'decimal:4',
        'total' => 'decimal:4',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Recalculate line totals based on quantity, price, discount, and tax rate.
     * Call this after changing any of the base fields.
     */
    public function recalculate(?string $taxTreatment = 'exclusive'): self
    {
        $qty = (float) $this->quantity;
        $price = (float) $this->unit_price;
        $disc = (float) $this->discount;

        $lineSubtotal = $qty * $price;
        $totalBeforeTax = max(0, $lineSubtotal - $disc);
        $taxAmt = $totalBeforeTax * ((float) $this->tax_rate / 100);

        $this->total_before_tax = round($totalBeforeTax, 4);
        $this->tax_amount = round($taxAmt, 4);
        $this->total = $taxTreatment === 'inclusive'
            ? round($totalBeforeTax, 4)
            : round($totalBeforeTax + $taxAmt, 4);

        return $this;
    }
}
