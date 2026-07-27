<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerOccasion extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'customer_id',
        'occasion_type',
        'title',
        'date',
        'repeats_annually',
        'notes',
        'preferred_contact_method',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'repeats_annually' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
