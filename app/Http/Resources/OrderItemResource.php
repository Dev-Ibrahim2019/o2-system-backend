<?php
// app/Http/Resources/OrderItemResource.php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'item_id'       => $this->item_id,
            'department_id' => $this->department_id,
            'item_name'     => $this->item_name,
            'item_name_ar'  => $this->item_name_ar,
            'unit_price'    => (float) $this->unit_price,
            'quantity'      => (float) $this->quantity,
            'total_price'   => (float) $this->total_price,
            'notes'         => $this->notes,
            'department'    => $this->whenLoaded('department', fn() => [
                'id'    => $this->department->id,
                'name'  => $this->department->name,
                'color' => $this->department->color,
                'icon'  => $this->department->icon,
            ]),
        ];
    }
}


// ─────────────────────────────────────────────────────────────────────────────
// في ملف منفصل: app/Http/Resources/ProductionTicketResource.php
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductionTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'order_id'      => $this->order_id,
            'ticket_number' => $this->ticket_number,
            'status'        => $this->status,
            'notes'         => $this->notes,
            'started_at'    => $this->started_at?->toIso8601String(),
            'completed_at'  => $this->completed_at?->toIso8601String(),
            'created_at'    => $this->created_at?->toIso8601String(),

            'department' => $this->whenLoaded('department', fn() => [
                'id'    => $this->department->id,
                'name'  => $this->department->name,
                'color' => $this->department->color,
                'icon'  => $this->department->icon,
            ]),

            'order' => $this->whenLoaded('order', fn() => [
                'id'           => $this->order->id,
                'order_number' => $this->order->order_number,
                'order_type'   => $this->order->order_type,
                'table_number' => $this->order->table_number,
                'note'         => $this->order->note,
            ]),

            'items' => $this->whenLoaded(
                'ticketItems',
                fn() =>
                $this->ticketItems->map(fn($ti) => [
                    'id'           => $ti->id,
                    'item_name'    => $ti->item_name,
                    'item_name_ar' => $ti->item_name_ar,
                    'quantity'     => (float) $ti->quantity,
                    'notes'        => $ti->notes,
                    'status'       => $ti->status,
                ])
            ),
        ];
    }
}
