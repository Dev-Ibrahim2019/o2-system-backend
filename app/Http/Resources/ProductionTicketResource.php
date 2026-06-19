<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductionTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'department_id' => $this->department_id,
            'ticket_number' => $this->ticket_number,
            'status' => $this->status,
            'priority' => $this->priority,
            'notes' => $this->notes,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'ready_at' => $this->ready_at?->toIso8601String(),
            'served_at' => $this->served_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            'department' => $this->whenLoaded('department', fn () => [
                'id' => $this->department->id,
                'name' => $this->department->name,
                'color' => $this->department->color,
                'icon' => $this->department->icon,
            ]),

            'order' => $this->whenLoaded('order', fn () => [
                'id' => $this->order->id,
                'order_number' => $this->order->order_number,
                'order_type' => $this->order->order_type,
                'table_number' => $this->order->table_number,
                'note' => $this->order->note,
            ]),

            'items' => $this->whenLoaded(
                'ticketItems',
                fn () => $this->ticketItems->map(fn ($ti) => [
                    'id' => $ti->id,
                    'order_item_id' => $ti->order_item_id,
                    'item_name' => $ti->orderItem?->item_name,
                    'item_name_ar' => $ti->orderItem?->item_name_ar,
                    'quantity' => (float) $ti->quantity,
                    'notes' => $ti->notes,
                    'status' => $ti->status,
                ])
            ),
        ];
    }
}
