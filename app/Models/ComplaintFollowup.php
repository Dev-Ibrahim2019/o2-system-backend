<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplaintFollowup extends Model
{
    protected $fillable = [
        'complaint_id',
        'user_id',
        'action',
        'notes',
        'old_status',
        'new_status',
        'followup_type',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'json',
    ];

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(CustomerComplaint::class, 'complaint_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
