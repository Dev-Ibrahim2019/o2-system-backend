<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Scopes\BranchScope;

/**
 * طھط³ظ„ط³ظ„ ط§ظ„ط¹ظ…ظ„:
 * 1) pending â€” ط­ظپط¸ ط§ظ„ط·ظ„ط¨ ظˆط¨ظ†ظˆط¯ظ‡ ظپظٹ orders + order_items
 * 2) confirm â€” ط±ط¨ط· ط§ظ„ط¨ظ†ظˆط¯ ط¨ط§ظ„ط£ظ‚ط³ط§ظ… ط¹ط¨ط± production_tickets + production_ticket_items (ظ„ظ„ط·ط¨ط§ط¹ط©/KDS)
 * 3) ظپط§طھظˆط±ط© â€” invoices + invoice_items (ظ†ط³ط®ط© ط±ط³ظ…ظٹط© ظ„ظ„ط¯ظپط¹)
 * 4) paid â€” payments ظ…ط±طھط¨ط·ط© ط¨ط§ظ„ظپط§طھظˆط±ط©
 */
class Order extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING_PAYMENT = 'PENDING_PAYMENT';
    public const STATUS_PREPARATION = 'PREPARATION';
    public const STATUS_ASSEMBLING = 'ASSEMBLING';
    public const STATUS_READY_FOR_DELIVERY = 'READY_FOR_DELIVERY';
    public const STATUS_OUT_FOR_DELIVERY = 'OUT_FOR_DELIVERY';
    public const STATUS_CANCELLATION_REQUESTED = 'CANCELLATION_REQUESTED';
    public const STATUS_DELIVERED = 'DELIVERED';
    public const STATUS_FAILED_DELIVERY = 'FAILED_DELIVERY';
    public const STATUS_CANCELLED = 'CANCELLED';

    // طھط·ط¨ظٹظ‚ BranchScope ط¹ظ„ظ‰ ط¬ظ…ظٹط¹ ط§ط³طھط¹ظ„ط§ظ…ط§طھ ط§ظ„ط·ظ„ط¨ط§طھ
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    protected $fillable = [
        'order_number',
        'dining_table_id',
        'branch_id',
        'cashier_id',
        'call_center_agent_id',
        'order_type',
        'source',
        'status',
        'table_number',
        'customer_name',
        'customer_phone',
        'customer_mobile',
        'customer_id',
        'customer_address_id',
        'delivery_zone_id',
        'delivery_fee',
        'delivery_address_snapshot',
        'employee_id',
        'supplier_id',
        'note',
        'needs_attention',
        'customer_service_flag',
        'customer_notes',
        'delivery_notes',
        'call_notes',
        'subtotal',
        'discount_value',
        'discount_type',
        'discount_amount',
        'engine_discount_amount',
        'total',
        'paid_at',
        'payment_status',
        'transaction_id',
        'assembled_at',
        'assembly_started_at',
        'assembler_id',
        'assembled_by',
        'assembly_duration_seconds',
        'delivery_started_at',
        'delivered_at',
        'delivery_employee_name',
        'driver_id',
        'delivery_assigned_by',
        'delivery_duration_seconds',
        'cancellation_reason',
        'cancelled_at',
        'is_urgent',
        'priority',
        'expedited_at',
        'expedited_by',
        'manual_adjustment','adjustment_reason','adjusted_by','adjusted_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:3',
        'discount_value' => 'decimal:3',
        'discount_amount' => 'decimal:3',
        'engine_discount_amount' => 'decimal:3',
        'total' => 'decimal:3',
        'delivery_fee' => 'decimal:3',
        'delivery_address_snapshot' => 'array',
        'needs_attention' => 'boolean',
        'customer_service_flag' => 'boolean',
        'paid_at' => 'datetime',
        'assembled_at' => 'datetime',
        'assembly_started_at' => 'datetime',
        'assembly_duration_seconds' => 'integer',
        'delivery_started_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'delivery_duration_seconds' => 'integer',
        'is_urgent' => 'boolean',
        'expedited_at' => 'datetime',
        'manual_adjustment' => 'decimal:3',
        'adjusted_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'cashier_id');
    }

    public function deliveryDriver(): BelongsTo { return $this->belongsTo(Employee::class, 'driver_id'); }
    public function deliveryZone(): BelongsTo { return $this->belongsTo(DeliveryZone::class); }
    public function deliveryTripStops(): HasMany { return $this->hasMany(DeliveryTripStop::class); }
    public function assembler(): BelongsTo { return $this->belongsTo(Employee::class, 'assembler_id'); }
    public function assembledByEmployee(): BelongsTo { return $this->belongsTo(Employee::class, 'assembled_by'); }
    public function executionEvents(): HasMany { return $this->hasMany(OrderExecutionEvent::class)->orderBy('occurred_at'); }
    public function customerExperience(): HasOne { return $this->hasOne(OrderCustomerExperience::class); }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** طھط°ط§ظƒط± ط§ظ„ط£ظ‚ط³ط§ظ… â€” ظƒظ„ طھط°ظƒط±ط© = ط¬ط²ط، ط·ط¨ط§ط¹ط©/ظ…ط·ط¨ط® ظ„ظ‚ط³ظ… ظˆط§ط­ط¯ */
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

    /** ط§ظ„ظ‚ظٹط¯ ط§ظ„ظ…ط­ط§ط³ط¨ظٹ (journal entry) ط§ظ„ظ…ط±طھط¨ط· ط¨ط§ظ„ط·ظ„ط¨ */
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
     * ط£ط¬ط²ط§ط، ط§ظ„ط·ظ„ط¨ ظ„ظ„ط·ط¨ط§ط¹ط© â€” ظƒظ„ ظ‚ط³ظ… ظ…ط¹ ط£طµظ†ط§ظپظ‡.
     * ط¨ط¹ط¯ confirm: ظ…ظ† ط§ظ„طھط°ط§ظƒط±. ظ‚ط¨ظ„ confirm: ظ…ط¹ط§ظٹظ†ط© ظ…ظ† order_items.groupBy(department_id)
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

    /** ط¥ط¹ط§ط¯ط© ط­ط³ط§ط¨ ط§ظ„ظ…ط¬ط§ظ…ظٹط¹ â€” ظ…ط­ط±ظƒ ط§ظ„ط®طµظˆظ…ط§طھ + ط§ظ„ط®طµظ… ط§ظ„ظٹط¯ظˆظٹ */
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

    public function getWaitingDurationSecondsAttribute(): ?int
    {
        return $this->paid_at ? max(0, $this->created_at->diffInSeconds($this->paid_at, false)) : null;
    }

    public function getPreparationDurationSecondsAttribute(): ?int
    {
        return ($this->paid_at && $this->assembled_at)
            ? max(0, $this->paid_at->diffInSeconds($this->assembled_at, false))
            : null;
    }

    public function getTotalLeadTimeSecondsAttribute(): ?int
    {
        return $this->delivered_at ? max(0, $this->created_at->diffInSeconds($this->delivered_at, false)) : null;
    }
}