<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerOccasion extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'occasionable_type',
        'occasionable_id',
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


    /**
     * When this occasion next falls, relative to $from.
     *
     * The single source for annual rolling. It lived in two places with two
     * different answers: Customer360QueryService::nextOccasion() rolled the
     * year correctly, while CallCenterService::getOccasionsByRange() compared
     * the stored date literally — so a birthday saved as 1993-05-05 was
     * invisible to every range except today/tomorrow, which happened to work
     * only because they matched on month+day instead.
     *
     * Returns null for a one-off whose date has already passed: it has no
     * next occurrence.
     */
    public function nextOccurrence(?Carbon $from = null): ?Carbon
    {
        if (! $this->date) {
            return null;
        }

        $from = ($from ?? Carbon::now())->copy()->startOfDay();
        $next = Carbon::parse($this->date)->startOfDay();

        if (! $this->repeats_annually) {
            return $next->lt($from) ? null : $next;
        }

        $next = $next->setYear($from->year);

        // Feb 29 on a common year lands on Mar 1 via setYear; that is the
        // conventional observance and keeps the occasion from disappearing.
        return $next->lt($from) ? $next->addYear() : $next;
    }

    /** How many days until the next occurrence, or null if there is none. */
    public function daysUntilNext(?Carbon $from = null): ?int
    {
        $next = $this->nextOccurrence($from);
        if ($next === null) {
            return null;
        }

        $start = ($from ?? Carbon::now())->copy()->startOfDay();

        return (int) $start->diffInDays($next, false);
    }

    /**
     * The customer or group this occasion belongs to.
     *
     * Replaces the old customer_id belongsTo. A group anniversary and a
     * customer birthday are the same kind of calendar entry, so they share one
     * table rather than one being modelled as a special customer.
     */
    public function occasionable(): MorphTo
    {
        return $this->morphTo();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
