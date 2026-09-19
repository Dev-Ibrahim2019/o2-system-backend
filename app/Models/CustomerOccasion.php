<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'assigned_user_id',
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

    /**
     * Named creator(), not createdBy().
     *
     * Laravel serializes a loaded relation under the snake_case of its
     * method name, so createdBy() would write an object over the
     * created_by integer column in the JSON — the same collision that
     * made a complaint's assigned_to read as an object once assignedTo
     * was eager-loaded. Under creator the id column survives intact.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Who follows up on this occasion — a CRM user, same distinction
     * customer_complaints.assigned_user_id already draws over its older,
     * Employee-based assigned_to column. Named assignedUser() (not
     * assignedTo()) for the same reason creator() isn't createdBy(): Laravel
     * serializes an eager-loaded relation under the snake_case of its own
     * method name, and assignedTo() would collide with and overwrite the
     * assigned_user_id integer column in the JSON.
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * The yearly diary: what was actually done for this occasion, newest first.
     *
     * Ordered on the relation rather than at every call site — the reverse
     * chronology is the whole point of the log, and a caller that eager-loads
     * it gets the same order as one that reads it lazily.
     */
    public function followups(): HasMany
    {
        // id breaks the tie: created_at has second resolution, so two
        // lines written in the same second would otherwise come back in
        // an order the database picked.
        return $this->hasMany(OccasionFollowup::class, 'occasion_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
