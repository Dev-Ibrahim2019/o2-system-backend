<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * تذكرة قسم — جزء واحد من الطلب للطباعة/شاشة المطبخ (بار، مطبخ، ...).
 * تُنشأ عند confirm بتجميع order_items حسب department_id.
 */
class ProductionTicket extends Model
{
    protected $fillable = [
        'order_id',
        'department_id',
        'ticket_number',
        'status',
        'priority',
        'sent_at',
        'started_at',
        'ready_at',
        'served_at',
        'created_by',
        'notes',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'started_at' => 'datetime',
        'ready_at' => 'datetime',
        'served_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function ticketItems(): HasMany
    {
        return $this->hasMany(ProductionTicketItem::class, 'production_ticket_id');
    }

    public static function generateTicketNumber(int $departmentId): string
    {
        $prefix = 'TKT-'.$departmentId.'-'.now()->format('Ymd').'-';
        $last = static::where('ticket_number', 'like', $prefix.'%')
            ->where('department_id', $departmentId)
            ->orderByDesc('id')
            ->value('ticket_number');

        $seq = $last ? (int) substr($last, -3) + 1 : 1;

        return $prefix.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }
}
