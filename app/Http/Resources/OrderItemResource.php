<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'department_id' => $this->department_id,
            'item_name' => $this->item_name,
            'item_name_ar' => $this->item_name_ar,
            'unit_price' => (float) $this->price,
            'quantity' => (float) $this->quantity,
            'total_price' => (float) $this->total,
            'status' => $this->status,
            'notes' => $this->notes,
            'sent_to_kitchen_at' => $this->sent_to_kitchen_at?->toIso8601String(),
            'is_printed_direct' => (bool) $this->is_printed_direct,
            'is_takeaway' => (bool) $this->is_takeaway,
            'item_prepared_at' => $this->item_prepared_at?->toIso8601String(),
            'prepared_duration_seconds' => $this->prepared_duration_seconds,
            'department' => $this->whenLoaded('department', fn () => [
                'id' => $this->department->id,
                'name' => $this->department->name,
                'color' => $this->department->color,
                'icon' => $this->department->icon,
            ]),
        ];
    }
}