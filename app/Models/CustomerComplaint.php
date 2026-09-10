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
        'assigned_user_id',
        'created_by',
        'title',
        'description',
        'type',
        'priority',
        'status',
        'resolved_at',
        'closed_at',
        'resolution_notes',
        'resolution_result',
        'severity',
        'is_sensitive',
        'show_alert',
        'branch_id',
        'channel',
        'department',
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

    /**
     * Where the complaint was filed from.
     *
     * Decided by the entry point, never by the person filling the form —
     * an agent cannot mislabel a call-centre complaint as a website one,
     * because neither request validator accepts the field at all.
     */
    const CHANNEL_CALL_CENTER = 'call_center';
    const CHANNEL_CRM = 'crm';
    /** Reserved in the enum; no endpoint writes it yet. */
    const CHANNEL_WEBSITE = 'website';

    const CHANNELS = [
        self::CHANNEL_CALL_CENTER,
        self::CHANNEL_CRM,
        self::CHANNEL_WEBSITE,
    ];

    const CHANNEL_LABELS = [
        self::CHANNEL_CALL_CENTER => 'الكول سنتر',
        self::CHANNEL_CRM => 'إدارة علاقات العملاء',
        self::CHANNEL_WEBSITE => 'الموقع الإلكتروني',
    ];

    /**
     * The operational department a complaint is about.
     *
     * Reporting dimension only. Unlike `channel` it is not derived from the
     * entry point — a call-centre agent records a complaint about the kitchen
     * — and unlike `is_sensitive` it carries no confidentiality, so anyone who
     * may write the complaint may set it. It has nothing to do with
     * assigned_to, which is about who works the ticket.
     */
    const DEPARTMENT_HOSPITALITY = 'hospitality';
    const DEPARTMENT_POS = 'pos';
    const DEPARTMENT_CALL_CENTER = 'call_center';
    const DEPARTMENT_KITCHEN = 'kitchen';
    const DEPARTMENT_DELIVERY = 'delivery';
    const DEPARTMENT_ACCOUNTING = 'accounting';
    const DEPARTMENT_MANAGEMENT = 'management';

    const DEPARTMENTS = [
        self::DEPARTMENT_HOSPITALITY,
        self::DEPARTMENT_POS,
        self::DEPARTMENT_CALL_CENTER,
        self::DEPARTMENT_KITCHEN,
        self::DEPARTMENT_DELIVERY,
        self::DEPARTMENT_ACCOUNTING,
        self::DEPARTMENT_MANAGEMENT,
    ];

    const DEPARTMENT_LABELS = [
        self::DEPARTMENT_HOSPITALITY => 'الضيافة',
        self::DEPARTMENT_POS => 'الكاشير الفوري',
        self::DEPARTMENT_CALL_CENTER => 'الكول سنتر',
        self::DEPARTMENT_KITCHEN => 'المطبخ',
        self::DEPARTMENT_DELIVERY => 'التوصيل',
        self::DEPARTMENT_ACCOUNTING => 'المحاسبة',
        self::DEPARTMENT_MANAGEMENT => 'الإدارة العامة',
    ];

    const PRIORITY_LOW = 'low';
    const PRIORITY_NORMAL = 'normal';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_CRITICAL = 'critical';

    /**
     * Which status can follow which.
     *
     * Until now the only check was "is this one of the seven values", so
     * closed → new was accepted silently. A complaint has a lifecycle; this
     * table is it. Reopening is deliberately possible from resolved, closed
     * and cancelled — but only to `open`, never straight back to `new`, and
     * never sideways into the middle of a flow it never entered.
     */
    public const ALLOWED_TRANSITIONS = [
        self::STATUS_NEW              => [self::STATUS_OPEN, self::STATUS_CANCELLED],
        self::STATUS_OPEN             => [self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS      => [self::STATUS_WAITING_CUSTOMER, self::STATUS_RESOLVED, self::STATUS_CANCELLED],
        self::STATUS_WAITING_CUSTOMER => [self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED, self::STATUS_CANCELLED],
        self::STATUS_RESOLVED         => [self::STATUS_CLOSED, self::STATUS_OPEN],
        self::STATUS_CLOSED           => [self::STATUS_OPEN],
        self::STATUS_CANCELLED        => [self::STATUS_OPEN],
    ];

    /** Arabic labels for the error message a reviewer actually reads. */
    public const STATUS_LABELS = [
        self::STATUS_NEW => 'جديدة',
        self::STATUS_OPEN => 'مفتوحة',
        self::STATUS_IN_PROGRESS => 'قيد المعالجة',
        self::STATUS_WAITING_CUSTOMER => 'بانتظار العميل',
        self::STATUS_RESOLVED => 'تمت المعالجة',
        self::STATUS_CLOSED => 'مغلقة',
        self::STATUS_CANCELLED => 'ملغاة',
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED_TRANSITIONS[$from] ?? [], true);
    }

    const SEVERITY_INFO = 'info';
    const SEVERITY_WARNING = 'warning';
    const SEVERITY_CRITICAL = 'critical';

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

    /**
     * The CRM agent who owns the ticket — a real login account, unlike
     * assignedTo() which points at the (login-less) employees table. This is
     * the one the CRM screens read and write; assigned_to is left for the
     * Call Center.
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function followups(): HasMany
    {
        return $this->hasMany(ComplaintFollowup::class, 'complaint_id');
    }

    /**
     * Hide sensitive complaints from anyone not cleared to see them.
     *
     * The single source for this rule. It used to live inline in
     * CrmController::complaints() while CallCenterService::getAllComplaints()
     * had no equivalent at all — two endpoints over the same table with two
     * different confidentiality standards, which is how the cross-customer
     * index came to return sensitive rows to a branch-manager. A scope means
     * a future third reader inherits the rule instead of re-deriving it.
     *
     * $user is nullable so an unauthenticated context fails closed rather
     * than throwing: no user, no clearance.
     */
    public function scopeVisibleTo($query, ?User $user)
    {
        if (! $user?->can('crm.view-sensitive-notes')) {
            $query->where('is_sensitive', false);
        }

        return $query;
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
