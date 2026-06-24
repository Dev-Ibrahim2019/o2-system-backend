<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiscountTargetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $targetLabel = match ($this->target_type) {
            'customer' => 'عميل',
            'employee' => 'موظف',
            'supplier' => 'مورد',
            'department' => 'قسم',
            'item' => 'صنف',
            'all_customers' => 'جميع العملاء',
            'all_employees' => 'جميع الموظفين',
            'all_suppliers' => 'جميع الموردين',
            'all' => 'الجميع',
            default => $this->target_type,
        };

        return [
            'id' => $this->id,
            'discount_id' => $this->discount_id,
            'target_type' => $this->target_type,
            'target_type_label' => $targetLabel,
            'target_id' => $this->target_id,
            'target_name' => $this->when($this->target_id, function () {
                return match ($this->target_type) {
                    'customer' => optional(\App\Models\Customer::find($this->target_id))->name,
                    'employee' => optional(\App\Models\Employee::find($this->target_id))->name,
                    'supplier' => optional(\App\Models\Supplier::find($this->target_id))->name,
                    'department' => optional(\App\Models\Department::find($this->target_id))->name,
                    'item' => optional(\App\Models\Item::find($this->target_id))->name,
                    default => null,
                };
            }),
        ];
    }
}
