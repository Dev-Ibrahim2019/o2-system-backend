<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use App\Support\Integration\IntegrationReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * تسلسل العمل:
 * 1) pending — حفظ الطلب وبنوده في orders + order_items
 * 2) confirm — ربط البنود بالأقسام عبر production_tickets + production_ticket_items (للطباعة/KDS)
 * 3) فاتورة — invoices + invoice_items (نسخة رسمية للدفع)
 * 4) paid — payments مرتبطة بالفاتورة
 */
class Order extends Model
{
    use SoftDeletes;

    public const PAYMENT_POLICY_MANUAL_CONFIRMATION = 'manual_confirmation';
    public const PAYMENT_POLICY_INSTANT_DEBIT = 'instant_debit';
    public const PAYMENT_POLICY_MIXED = 'mixed';

    public const PAYMENT_POLICIES = [
        self::PAYMENT_POLICY_MANUAL_CONFIRMATION,
        self::PAYMENT_POLICY_INSTANT_DEBIT,
        self::PAYMENT_POLICY_MIXED,
    ];

    public const PAYMENT_STATUS_UNPAID = 'unpaid';
    public const PAYMENT_STATUS_AWAITING_CONFIRMATION = 'awaiting_confirmation';
    public const PAYMENT_STATUS_PROCESSING = 'processing';
    public const PAYMENT_STATUS_PAID = 'paid';
    public const PAYMENT_STATUS_FAILED = 'failed';
    public const PAYMENT_STATUS_REFUNDED = 'refunded';

    public const KITCHEN_RELEASE_STATUS_HELD = 'held';
    public const KITCHEN_RELEASE_STATUS_RELEASING = 'releasing';
    public const KITCHEN_RELEASE_STATUS_RELEASED = 'released';
    public const KITCHEN_RELEASE_STATUS_FAILED = 'release_failed';

    public const PAYMENT_STATUSES = [
        self::PAYMENT_STATUS_UNPAID,
        self::PAYMENT_STATUS_AWAITING_CONFIRMATION,
        self::PAYMENT_STATUS_PROCESSING,
        self::PAYMENT_STATUS_PAID,
        self::PAYMENT_STATUS_FAILED,
        self::PAYMENT_STATUS_REFUNDED,
    ];

    public const KITCHEN_RELEASE_STATUSES = [
        self::KITCHEN_RELEASE_STATUS_HELD,
        self::KITCHEN_RELEASE_STATUS_RELEASING,
        self::KITCHEN_RELEASE_STATUS_RELEASED,
        self::KITCHEN_RELEASE_STATUS_FAILED,
    ];

    // تطبيق BranchScope على جميع استعلامات الطلبات
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);

        static::creating(function (Order $order): void {
            if (blank($order->public_ref)) {
                $order->public_ref = IntegrationReference::order();
            } elseif (! IntegrationReference::isValid($order->public_ref, IntegrationReference::ORDER_PREFIX)) {
                throw new LogicException('Order public_ref must be a typed Order integration reference.');
            }
        });

        static::updating(function (Order $order): void {
            if ($order->isDirty('public_ref') && filled($order->getOriginal('public_ref'))) {
                throw new LogicException('Order public_ref is immutable once issued.');
            }
        });

        // خانات الطلبات النشطة (كول سنتر فقط): حجز عند الإنشاء، وتحرير عند الدفع/الإلغاء/الإغلاق.
        // فشلها ما لازم يوقف إنشاء الطلب أو الدفع — أمر reconcile الدوري بيصلّحها.
        static::saved(function (Order $order): void {
            if ($order->source !== 'call_center') {
                return;
            }
            if (! $order->wasRecentlyCreated && ! $order->wasChanged(['status', 'branch_id'])) {
                return;
            }
            try {
                app(\App\Services\CallCenter\OrderSlotService::class)->sync($order);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    protected $fillable = [
        'order_number',
        'dining_table_id',
        'branch_id',
        'cashier_id',
        'shift_id',
        'opened_by',
        'closed_by',
        'printed_by',
        'printed_at',
        'editing_by',
        'editing_until',
        'order_type',
        'source',
        'status',
        'payment_policy',
        'payment_status',
        'kitchen_release_status',
        'kitchen_released_at',
        'kitchen_released_by',
        'table_number',
        'customer_count',
        'seated_at',
        'customer_name',
        // The name typed when it differed from the customer's official one.
        // Written only on a real conflict; see IdentityConflictService.
        'incoming_customer_name',
        'customer_phone',
        'customer_id',
        'employee_id',
        'supplier_id',
        'customer_address_id',
        'delivery_zone_id',
        'delivery_fee',
        'delivery_address_snapshot',
        'delivery_notes',
        'call_notes',
        'note',
        'subtotal',
        'discount_value',
        'discount_type',
        'discount_amount',
        'engine_discount_amount',
        'tax_rate',
        'tax_amount',
        'scheduled_at',
        'payments',
        'total',
        'cancellation_reason',
        'cancelled_at',
        'driver_id',
        'delivery_assigned_at',
        'delivered_at',
        'executed_at',
        'execution_attempts',
        'execution_failed_reason',
        'closed_at',
        'closed_by',
        'reopened_at',
        'reopen_reason',
    ];

    protected $casts = [
        'subtotal' => 'decimal:3',
        'discount_value' => 'decimal:3',
        'discount_amount' => 'decimal:3',
        'engine_discount_amount' => 'decimal:3',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:3',
        'scheduled_at' => 'datetime',
        'payments' => 'array',
        'total' => 'decimal:3',
        'seated_at' => 'datetime',
        'printed_at' => 'datetime',
        'customer_count' => 'integer',
        'delivery_fee' => 'decimal:3',
        'delivery_address_snapshot' => 'array',
        'kitchen_released_at' => 'datetime',
        // Cast, but deliberately not fillable: paid_at is written only by
        // OrderPaymentService::markPaid(), which assigns it directly. Adding
        // it to $fillable would put it inside the reach of
        // OrderController@update's mass assignment, which is how 'paid'
        // became a fourth, unguarded payment path in the first place.
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'delivery_assigned_at' => 'datetime',
        'delivered_at' => 'datetime',
        'executed_at' => 'datetime',
        'editing_until' => 'datetime',
        'execution_attempts' => 'integer',
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
    ];

    /**
     * Orders that are finished — nothing operational is left to do on them.
     * The one definition the whole codebase should read, because "finished"
     * is genuinely not a single status value here: the POS, Call Center,
     * hospitality and customer-portal flows were deliberately never unified
     * (see OrderStatusService's CALL_CENTER_TRANSITIONS docblock), so each
     * ends on a different value.
     *
     *  - closed / cancelled / CANCELLED — terminal for every flow. These are
     *    exactly the values OrderStatusService::maybeAutoClose() and
     *    forceComplete() treat as "already done, nothing to do" themselves.
     *  - served / DELIVERED — the order was handed over. Both transition only
     *    to 'closed', so there is no operational step left either way.
     *  - paid — terminal for POS/hospitality/portal orders, where payment is
     *    the last step. NOT terminal for call-center orders: those are paid
     *    BEFORE the kitchen is released ('paid' => confirmed/in_progress/ready
     *    in the transition map), so a call-center order sitting at 'paid' is
     *    still very much in flight and must not be treated as history.
     *
     * Kept as a scope rather than a constant because of that last, source-
     * dependent case — a flat status list cannot express it correctly.
     */
    public function scopeFinalized($query)
    {
        return $query->where(function ($q) {
            $q->whereIn('status', ['closed', 'cancelled', 'CANCELLED', 'served', 'DELIVERED'])
                ->orWhere(function ($paid) {
                    $paid->where('status', 'paid')->where('source', '!=', 'call_center');
                });
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The inverse of Customer::orders(), which has existed all along — this
     * side was simply missing, so orders.customer_id had no relation to read
     * it through. Nullable by design: a walk-in order legitimately has no
     * customer.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'cashier_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by');
    }

    /** موظف التوصيل المُسنَد للطلب (عمود driver_id — كان موجودًا بلا استخدام قبل هذه الميزة) */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'driver_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** تذاكر الأقسام — كل تذكرة = جزء طباعة/مطبخ لقسم واحد */
    public function tickets(): HasMany
    {
        return $this->hasMany(ProductionTicket::class);
    }

    public function productionTickets(): HasMany
    {
        return $this->tickets();
    }

    public function diningTable()
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function feedback(): HasOne
    {
        return $this->hasOne(OrderFeedback::class);
    }

    public function paymentConfirmations(): HasMany
    {
        return $this->hasMany(PaymentConfirmation::class);
    }

    public function kitchenReleasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'kitchen_released_by');
    }

    /** القيد المحاسبي (journal entry) المرتبط بالطلب */
    public function journalEntry()
    {
        return Transaction::where('source_type', self::class)
            ->where('source_id', $this->id)
            ->where('type', 'sale')
            ->first();
    }

    public static function generateOrderNumber(): string
    {
        $prefix = 'ORD-'.now()->format('Ymd').'-';
        $last = static::where('order_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('order_number');

        $seq = $last ? (int) substr($last, -4) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * أجزاء الطلب للطباعة — كل قسم مع أصنافه.
     * بعد confirm: من التذاكر. قبل confirm: معاينة من order_items.groupBy(department_id)
     */
    public function sectionsForPrint(): array
    {
        if ($this->relationLoaded('tickets') ? $this->tickets->isNotEmpty() : $this->tickets()->exists()) {
            return $this->sectionsFromTickets();
        }

        return $this->sectionsFromOrderItems();
    }

    protected function sectionsFromTickets(): array
    {
        $tickets = $this->tickets()
            ->with(['department', 'ticketItems.orderItem'])
            ->orderBy('department_id')
            ->get();

        return $tickets->map(fn (ProductionTicket $ticket) => [
            'source' => 'ticket',
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'department_id' => $ticket->department_id,
            'department' => $ticket->department ? [
                'id' => $ticket->department->id,
                'name' => $ticket->department->name,
                'name_ar' => $ticket->department->nameAr,
                'color' => $ticket->department->color,
                'icon' => $ticket->department->icon,
            ] : null,
            'items' => $ticket->ticketItems->map(fn (ProductionTicketItem $ti) => [
                'order_item_id' => $ti->order_item_id,
                'item_id' => $ti->orderItem?->item_id,
                'item_name' => $ti->orderItem?->item_name,
                'item_name_ar' => $ti->orderItem?->item_name_ar,
                'quantity' => (float) ($ti->orderItem?->quantity ?? $ti->quantity),
                'price' => (float) ($ti->orderItem?->price ?? 0),
                'total' => (float) ($ti->orderItem?->total ?? 0),
                'notes' => $ti->notes ?? $ti->orderItem?->notes,
            ])->values()->all(),
        ])->values()->all();
    }

    protected function sectionsFromOrderItems(): array
    {
        $grouped = $this->items()->with('department')->get()->groupBy('department_id');

        return $grouped->map(function ($items, $deptId) {
            $department = $items->first()->department;

            return [
                'source' => 'order_items',
                'ticket_id' => null,
                'ticket_number' => null,
                'department_id' => $deptId ? (int) $deptId : null,
                'department' => $department ? [
                    'id' => $department->id,
                    'name' => $department->name,
                    'name_ar' => $department->nameAr,
                    'color' => $department->color,
                    'icon' => $department->icon,
                ] : null,
                'items' => $items->map(fn (OrderItem $oi) => [
                    'order_item_id' => $oi->id,
                    'item_id' => $oi->item_id,
                    'item_name' => $oi->item_name,
                    'item_name_ar' => $oi->item_name_ar,
                    'quantity' => (float) $oi->quantity,
                    'price' => (float) $oi->price,
                    'total' => (float) $oi->total,
                    'notes' => $oi->notes,
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /** إعادة حساب المجاميع — محرك الخصومات + الخصم اليدوي */
    public function recalculateTotals(): void
    {
        app(\App\Services\Order\OrderPricingService::class)->recalculateAndSave($this);
    }

    // ── Helper Methods for Unsent Items Flow ──────────────────────────

    /**
     * هل يوجد عناصر جديدة لم تُرسل للمطبخ بعد؟
     */
    public function hasUnsentItems(): bool
    {
        return $this->items()->where('status', 'pending')->exists();
    }

    /**
     * جلب العناصر غير المرحّلة فقط (لم يُرسل لها production ticket)
     */
    public function unsentItems()
    {
        return $this->items()->where('status', 'pending');
    }

    /**
     * هل الطلب في حالة تسمح بإضافة عناصر جديدة؟
     */
    public function canAddItems(): bool
    {
        return in_array($this->status, ['pending', 'pending_confirmation', 'confirmed', 'in_progress']);
    }

    /**
     * هل الطلب في حالة تسمح بالترحيل؟
     */
    public function canBeConfirmed(): bool
    {
        return in_array($this->status, ['pending', 'pending_confirmation']);
    }
}
