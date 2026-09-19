<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderActivityLog extends Model
{
    // اسم الجدول الفعلي بلا s نهائية (order_activity_log) — يخالف تخمين Eloquent الافتراضي
    // بالجمع (order_activity_logs)، فلازم تصريح صريح وإلا كل استعلام يفشل بـ "table not found".
    protected $table = 'order_activity_log';

    public $timestamps = false;

    protected $fillable = [
        'order_id', 'actor_id', 'action_type', 'from_status', 'to_status', 'note', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
