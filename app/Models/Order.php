<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Scopes\BranchScope;

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

    // تطبيق BranchScope على جميع استعلامات الطلبات
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
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
        'order_type',
        'status',
        'table_number',
        'customer_count',
        'seated_at',
        'customer_name',
        'customer_phone',
        'customer_id',
        'employee_id',
        'supplier_id',
        'note',
        'subtotal',
        'discount_value',
        'discount_type',
        'discount_amount',
        'engine_discount_amount',
        'total',
    ];

    protected $casts = [
        'subtotal' => 'decimal:3',
        'discount_value' => 'decimal:3',
        'discount_amount' => 'decimal:3',
        'engine_discount_amount' => 'decimal:3',
        'total' => 'decimal:3',
        'seated_at' => 'datetime',
        'printed_at' => 'datetime',
        'customer_count' => 'integer',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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
