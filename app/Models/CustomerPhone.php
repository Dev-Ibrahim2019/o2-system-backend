<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerPhone extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'customer_id',
        'phone',
        'normalized_phone',
        'type',
        'is_primary',
        'is_verified',
        // Set only by an identity-conflict resolution; exempts the row from
        // the exclusive_phone unique index. See the
        // allow_shared_phone_numbers_on_customer_phones migration.
        'is_shared',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_verified' => 'boolean',
        'is_shared' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
