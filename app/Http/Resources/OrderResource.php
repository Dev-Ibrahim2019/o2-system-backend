<?php
// app/Http/Resources/OrderResource.php

namespace App\Http\Resources;

use App\Http\Resources\OrderItemResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'order_number'   => $this->order_number,
            'branch_id'      => $this->branch_id,
            'cashier_id'     => $this->cashier_id,
            'order_type'     => $this->order_type,
            'status'         => $this->status,
            'table_number'   => $this->table_number,
            'customer_name'  => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'note'           => $this->note,

            'subtotal'        => (float) $this->subtotal,
            'discount_value'  => (float) $this->discount_value,
            'discount_type'   => $this->discount_type,
            'discount_amount' => (float) $this->discount_amount,
            'total'           => (float) $this->total,
            'payment_method'  => $this->payment_method,

            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'tickets' => ProductionTicketResource::collection($this->whenLoaded('tickets')),
            'cashier' => $this->whenLoaded('cashier', fn() => [
                'id'   => $this->cashier->id,
                'name' => $this->cashier->name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
