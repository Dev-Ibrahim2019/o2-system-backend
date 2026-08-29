<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DiningTable extends Model
{
    use HasFactory;

    protected $fillable = [
        'dining_zone_id',
        'branch_id',
        'code',
        'table_number',
        'qr_code',
        'qr_url',
        'capacity',
        'status',
        'current_order_id',
        'seated_at',
        'customer_count',
        'last_order_at',
        'merged_with_id',
        'waiter_called_at',
    ];

    protected $casts = [
        'seated_at' => 'datetime',
        'last_order_at' => 'datetime',
        'waiter_called_at' => 'datetime',
        'customer_count' => 'integer',
        'capacity' => 'integer',
    ];

    public function zone()
    {
        return $this->belongsTo(DiningZone::class, 'dining_zone_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function getBranchViaZoneAttribute()
    {
        return $this->zone?->branch;
    }

    public function currentOrder()
    {
        return $this->belongsTo(Order::class, 'current_order_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'dining_table_id');
    }

    /**
     * تحديث حالة الطاولة تلقائياً بناءً على الأحداث (تسكين، طلب، دفع، إلغاء)
     */
    public function setOccupied(?int $orderId = null, ?int $customerCount = null): void
    {
        $this->update([
            'status' => 'OCCUPIED',
            'current_order_id' => $orderId ?? $this->current_order_id,
            'seated_at' => $this->seated_at ?? now(),
            'customer_count' => $customerCount ?? $this->customer_count,
        ]);
    }

    public function setPaymentPending(): void
    {
        $this->update(['status' => 'PAYMENT_PENDING']);
    }

    public function setPaid(): void
    {
        $this->update([
            'status' => 'PAID',
            'current_order_id' => null,
            'seated_at' => null,
            'customer_count' => 0,
            'last_order_at' => now(),
            'waiter_called_at' => null,
        ]);
    }

    public function setAvailable(): void
    {
        $this->update([
            'status' => 'AVAILABLE',
            'current_order_id' => null,
            'seated_at' => null,
            'customer_count' => 0,
            'last_order_at' => now(),
            'waiter_called_at' => null,
        ]);
    }

    public function setCleaning(): void
    {
        $this->update(['status' => 'CLEANING']);
    }

    public function callWaiter(): void
    {
        $this->update(['waiter_called_at' => now()]);
    }

    public function acknowledgeWaiterCall(): void
    {
        $this->update(['waiter_called_at' => null]);
    }

    public function setReserved(): void
    {
        $this->update(['status' => 'RESERVED']);
    }

    public function setPendingConfirmation(): void
    {
        $this->update([
            'status' => 'PENDING_CONFIRMATION',
            'seated_at' => $this->seated_at ?? now(),
            'customer_count' => max($this->customer_count, 1),
        ]);
    }

    public function setHasOrder(?int $orderId = null): void
    {
        $this->update([
            'status' => 'OCCUPIED',
            'current_order_id' => $orderId ?? $this->current_order_id,
        ]);
    }

    public function setMerged(int $targetTableId): void
    {
        $this->update([
            'status' => 'MERGED',
            'merged_with_id' => $targetTableId,
            'current_order_id' => null,
            'seated_at' => null,
            'customer_count' => 0,
        ]);
    }

    public function setUnmerged(): void
    {
        $this->update([
            'status' => 'AVAILABLE',
            'merged_with_id' => null,
            'current_order_id' => null,
            'seated_at' => null,
            'customer_count' => 0,
        ]);
    }

    public function isMerged(): bool
    {
        return $this->status === 'MERGED' && $this->merged_with_id !== null;
    }

    public function mergedWith()
    {
        return $this->belongsTo(DiningTable::class, 'merged_with_id');
    }

    public function mergedTables()
    {
        return $this->hasMany(DiningTable::class, 'merged_with_id');
    }
}
