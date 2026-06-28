<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * نموذج المستهدفين بالخصم — يحدد من/ماذا يستفيد من الخصم
 * 
 * target_type: customer, employee, supplier, department, item, 
 *              all_customers, all_employees, all_suppliers, all
 * target_id: معرف المستهدف (null للأنواع العامة)
 */
class DiscountTarget extends Model
{
    protected $fillable = [
        'discount_id',
        'target_type',
        'target_id',
    ];

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    /**
     * الحصول على نموذج المستهدف (إذا كان محدداً)
     */
    public function target()
    {
        return match ($this->target_type) {
            'customer' => $this->belongsTo(Customer::class, 'target_id'),
            'employee' => $this->belongsTo(Employee::class, 'target_id'),
            'supplier' => $this->belongsTo(Supplier::class, 'target_id'),
            'department' => $this->belongsTo(Department::class, 'target_id'),
            'item' => $this->belongsTo(Item::class, 'target_id'),
            default => null,
        };
    }
}
