<?php
// app/Models/ProductionTicket.php

namespace App\Models;

use App\Models\Department;
use App\Models\Order;
use App\Models\ProductionTicketItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionTicket extends Model
{
    protected $fillable = [
        'order_id',
        'department_id',
        'ticket_number',
        'status',
        'started_at',
        'completed_at',
        'notes',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
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
        return $this->hasMany(ProductionTicketItem::class, 'ticket_id');
    }

    // ── رقم تذكرة داخل القسم (تلقائي يومي) ──────────────────────────────────
    public static function generateTicketNumber(int $departmentId): string
    {
        $prefix = 'TKT-' . $departmentId . '-' . now()->format('Ymd') . '-';
        $last   = static::where('ticket_number', 'like', $prefix . '%')
            ->where('department_id', $departmentId)
            ->orderByDesc('id')
            ->value('ticket_number');

        $seq = $last ? (int) substr($last, -3) + 1 : 1;

        return $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);
    }
}
