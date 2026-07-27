<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderCustomerExperience extends Model
{
    protected $fillable = ['order_id', 'customer_id', 'recorded_by', 'food_rating', 'delivery_rating', 'speed_rating', 'contacted', 'notes'];

    protected $casts = [
        'food_rating' => 'integer',
        'delivery_rating' => 'integer',
        'speed_rating' => 'integer',
        'contacted' => 'boolean',
    ];

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function recordedBy(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
}
