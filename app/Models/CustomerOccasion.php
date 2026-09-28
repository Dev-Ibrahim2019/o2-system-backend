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

    /**
     * SQL pre-filter: rows whose next occurrence MIGHT fall in [$start, $end].
     *
     * A superset, never a subset — nextOccurrence() stays the authoritative
     * check after the fetch. It exists so the occasions list/summary and the
     * daily reminder stop loading every active occasion into memory:
     *  - one-off occasions: their own date must be inside the window;
     *  - annual ones: their month-day must be inside the window's month-day
     *    span (wrapping over New Year), plus every Feb 29 row, since
     *    nextOccurrence() observes those on Mar 1 in a common year.
     * An open-ended window ($end null) only drops one-off dates already past.
     */
    public function scopeMayOccurBetween($query, Carbon $start, ?Carbon $end)
    {
        $start = $start->copy()->startOfDay();
        $oneOff = fn ($q) => $q->where(fn ($w) => $w->where('repeats_annually', false)->orWhereNull('repeats_annually'));

        if ($end === null || $start->diffInDays($end) >= 365) {
            return $query->where(fn ($q) => $q
                ->where('repeats_annually', true)
                ->orWhere(fn ($once) => $oneOff($once)
                    ->where('date', '>=', $start->toDateString())
                    ->when($end, fn ($b) => $b->where('date', '<=', $end->toDateString()))));
        }

        $md = static::monthDaySql();
        $from = $start->format('m-d');
        $to = $end->format('m-d');
        $wraps = $end->year > $start->year;

        return $query->where(fn ($q) => $q
            ->where(fn ($once) => $oneOff($once)->whereBetween('date', [$start->toDateString(), $end->toDateString()]))
            ->orWhere(fn ($annual) => $annual
                ->where('repeats_annually', true)
                ->where(fn ($w) => $w
                    ->when(
                        $wraps,
                        fn ($b) => $b->whereRaw("{$md} >= ?", [$from])->orWhereRaw("{$md} <= ?", [$to]),
                        fn ($b) => $b->whereRaw("{$md} BETWEEN ? AND ?", [$from, $to]),
                    )
                    ->orWhereRaw("{$md} = ?", ['02-29']))));
    }

    /** "MM-DD" of the `date` column, in whichever SQL dialect is connected. */
    private static function monthDaySql(): string
    {
        return match ((new static)->getConnection()->getDriverName()) {
            'sqlite' => "strftime('%m-%d', date)",
            'pgsql' => "to_char(date, 'MM-DD')",
            default => "DATE_FORMAT(date, '%m-%d')",
        };
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
