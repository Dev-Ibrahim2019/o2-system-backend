<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerComplaint extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'customer_id',
        'order_id',
        'invoice_id',
        'assigned_to',
        'created_by',
        'title',
        'subject',
        'description',
        'type',
        'priority',
        'status',
        'resolved_at',
        'closed_at',
        'resolution_notes',
        'resolution',
        'resolution_result',
        'severity',
        'is_sensitive',
        'show_alert',
        'branch_id',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'is_sensitive' => 'boolean',
        'show_alert' => 'boolean',
    ];

    const STATUS_NEW = 'new';
    const STATUS_OPEN = 'open';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_WAITING_CUSTOMER = 'waiting_customer';
    const STATUS_RESOLVED = 'resolved';
    const STATUS_CLOSED = 'closed';
    const STATUS_CANCELLED = 'cancelled';

    const PRIORITY_LOW = 'low';
    const PRIORITY_NORMAL = 'normal';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_CRITICAL = 'critical';

    const SEVERITY_INFO = 'info';
    const SEVERITY_WARNING = 'warning';
    const SEVERITY_CRITICAL = 'critical';

    public function fill(array $attributes)
    {
        parent::fill($attributes);
        if (empty($this->title) && !empty($this->subject)) {
            $this->title = $this->subject;
        }
        if (empty($this->resolution_notes) && !empty($this->resolution)) {
            $this->resolution_notes = $this->resolution;
        }
        return $this;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function followups(): HasMany
    {
        return $this->hasMany(ComplaintFollowup::class, 'complaint_id');
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [self::STATUS_NEW, self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_WAITING_CUSTOMER]);
    }

    public function scopeAlertable($query)
    {
        return $query->where('show_alert', true)
            ->whereIn('status', [self::STATUS_NEW, self::STATUS_OPEN, self::STATUS_IN_PROGRESS])
            ->orWhere(function ($q) {
                $q->where('status', self::STATUS_RESOLVED)
                    ->where('is_sensitive', true)
                    ->where('resolved_at', '>=', now()->subDays(3));
            });
    }
}
