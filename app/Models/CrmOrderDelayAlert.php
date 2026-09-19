<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per (order, threshold) pair the delay-alert job has already
 * notified CRM staff about — see CrmOrderDelayAlertService::checkAndNotify().
 * Existence of the row is all that matters for the job's idempotency;
 * `notified_at` is kept only for support/debugging visibility, nothing reads
 * it back.
 */
class CrmOrderDelayAlert extends Model
{
    public $timestamps = false;

    protected $fillable = ['order_id', 'threshold_minutes', 'notified_at'];

    protected $casts = [
        'notified_at' => 'datetime',
    ];
}
