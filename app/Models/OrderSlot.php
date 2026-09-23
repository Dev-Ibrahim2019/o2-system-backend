<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderSlot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'branch_id',
        'order_id',
        'slot_number',
        'assigned_at',
        'released_at',
        'release_reason',
        'active_slot',
        'active_order_id',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withoutGlobalScopes()->withTrashed();
    }
}
