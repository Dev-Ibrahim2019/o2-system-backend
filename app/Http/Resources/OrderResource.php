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
            'branch_id'        => $this->branch_id,
            'cashier_id'       => $this->cashier_id,
            'call_center_agent_id' => $this->call_center_agent_id,
            'order_type'       => $this->order_type,
            'source'           => $this->source,
            'status'           => $this->status,
            'table_number'     => $this->table_number,
            'customer_name'    => $this->customer_name,
            'customer_phone'   => $this->customer_phone,
            'customer_mobile'  => $this->customer_mobile,
            'customer_address_id' => $this->customer_address_id,
            'delivery_zone_id' => $this->delivery_zone_id,
            'delivery_zone' => $this->whenLoaded('deliveryZone'),
            'delivery_address_snapshot' => $this->delivery_address_snapshot,
            'customer_notes'   => $this->customer_notes,
            'delivery_notes'   => $this->delivery_notes,
            'call_notes'       => $this->call_notes,
            'needs_attention'  => (bool) $this->needs_attention,
            'customer_service_flag' => (bool) $this->customer_service_flag,
            'is_urgent'        => (bool) $this->is_urgent,
            'priority'         => $this->priority,
            'expedited_at'     => $this->expedited_at?->toIso8601String(),
            'note'             => $this->note,

            'subtotal'         => (float) $this->subtotal,
            'discount_value'   => (float) $this->discount_value,
            'discount_type'    => $this->discount_type,
            'discount_amount'  => (float) $this->discount_amount,
            'engine_discount_amount' => (float) ($this->engine_discount_amount ?? 0),
            'total_discount'   => (float) ($this->engine_discount_amount ?? 0) + (float) $this->discount_amount,
            'total'            => (float) $this->total,
            'delivery_fee'     => (float) ($this->delivery_fee ?? 0),
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

            'paid_at' => $this->paid_at?->toIso8601String(),
            'payment_status' => $this->payment_status,
            'transaction_id' => $this->transaction_id,
            'assembled_at' => $this->assembled_at?->toIso8601String(),
            'assembly_started_at' => $this->assembly_started_at?->toIso8601String(),
            'assembler_id' => $this->assembler_id,
            'assembled_by' => $this->assembled_by,
            'assembly_duration_seconds' => $this->assembly_duration_seconds,
            'assembler' => $this->when($this->relationLoaded('assembler') && $this->assembler, fn () => ['id'=>$this->assembler->id,'name'=>$this->assembler->name]),
            'assembled_by_employee' => $this->when($this->relationLoaded('assembledByEmployee') && $this->assembledByEmployee, fn () => ['id'=>$this->assembledByEmployee->id,'name'=>$this->assembledByEmployee->name]),
            'execution_events_count' => $this->whenCounted('executionEvents'),
            'delivery_started_at' => $this->delivery_started_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'delivery_employee_name' => $this->delivery_employee_name,
            'driver_id' => $this->driver_id,
            'driver' => $this->when($this->relationLoaded('deliveryDriver') && $this->deliveryDriver, fn () => [
                'id'=>$this->deliveryDriver->id, 'name'=>$this->deliveryDriver->name, 'phone'=>$this->deliveryDriver->phone,
                'vehicle_type'=>$this->deliveryDriver->vehicle_type,
                'branch'=>$this->deliveryDriver->relationLoaded('branch') && $this->deliveryDriver->branch ? ['id'=>$this->deliveryDriver->branch->id, 'name'=>$this->deliveryDriver->branch->name] : null,
            ]),
            'delivery_duration_seconds' => $this->delivery_duration_seconds,
            'waiting_duration_seconds' => $this->waiting_duration_seconds,
            'preparation_duration_seconds' => $this->preparation_duration_seconds,
            'total_lead_time_seconds' => $this->total_lead_time_seconds,
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}


