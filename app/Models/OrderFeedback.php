<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderFeedback extends Model
{
    protected $table = 'order_feedback';

    protected $fillable = [
        'order_id',
        'customer_id',
        'food_quality',
        'service_quality',
        'delivery_speed',
        'notes',
        'recorded_by',
    ];

    protected $casts = [
        'food_quality' => 'integer',
        'service_quality' => 'integer',
        'delivery_speed' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
