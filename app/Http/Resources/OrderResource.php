<?php
// app/Http/Resources/OrderResource.php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'order_number'     => $this->order_number,
            'dining_table_id'  => $this->dining_table_id,
            'branch_id'        => $this->branch_id,
            'cashier_id'       => $this->cashier_id,
            'order_type'       => $this->order_type,
            'status'           => $this->status,
            'table_number'     => $this->table_number,
            'customer_name'    => $this->customer_name,
            'customer_phone'   => $this->customer_phone,
            'note'             => $this->note,

            'subtotal'         => (float) $this->subtotal,
            'discount_value'   => (float) $this->discount_value,
            'discount_type'    => $this->discount_type,
            'discount_amount'  => (float) $this->discount_amount,
            'engine_discount_amount' => (float) ($this->engine_discount_amount ?? 0),
            'total_discount'   => (float) ($this->engine_discount_amount ?? 0) + (float) $this->discount_amount,
            'total'            => (float) $this->total,
            'grand_total'      => (float) $this->total,
            'customer_id'      => $this->customer_id,
            'employee_id'      => $this->employee_id,
            'supplier_id'      => $this->supplier_id,

            'items'   => OrderItemResource::collection($this->whenLoaded('items')),
            'invoice' => $this->whenLoaded('invoice', fn () => new InvoiceResource($this->invoice)),
            'tickets' => ProductionTicketResource::collection($this->whenLoaded('tickets')),
            'cashier' => $this->whenLoaded('cashier', fn() => [
                'id'   => $this->cashier->id,
                'name' => $this->cashier->name,
            ]),

            'has_unsent_items' => $this->hasUnsentItems(),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
