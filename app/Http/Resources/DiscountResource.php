<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiscountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'code' => $this->code,
            'description' => $this->description,
            'discount_type' => $this->discount_type,
            'discount_type_label' => match ($this->discount_type) {
                'percentage' => 'نسبة مئوية',
                'fixed_amount' => 'مبلغ ثابت',
                'price_override' => 'تجاوز السعر',
                'buy_x_get_y' => 'اشتر X واحصل على Y',
                default => $this->discount_type,
            },
            'value' => (float) $this->value,
            'priority' => $this->priority,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'is_active' => $this->is_active,
            'is_valid' => $this->isValid(),
            'max_discount_amount' => $this->max_discount_amount ? (float) $this->max_discount_amount : null,
            'min_order_amount' => $this->min_order_amount ? (float) $this->min_order_amount : null,
            'targets' => DiscountTargetResource::collection($this->whenLoaded('targets')),
            'creator' => $this->whenLoaded('creator', fn() => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'usage_logs' => $this->whenLoaded('usageLogs'),
            'usage_count' => $this->whenCounted('usageLogs', fn() => $this->usage_logs_count),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
