<?php
// app/Http/Resources/OrderResource.php

namespace App\Http\Resources;

use App\Services\CallCenter\CallCenterService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // مصدر واحد للحقيقة لتحديد Active/Closed وحالة الدفع — نفس المنطق المستخدم بصفحات
        // الكول سنتر (Active/Closed Orders)، حتى ما يصير عندنا شرط مختلف بكل مكان.
        $invoiceStatus = $this->whenLoaded('invoice', fn () => $this->invoice?->status, null);

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

            'customer_address_id'       => $this->customer_address_id,
            'delivery_zone_id'          => $this->delivery_zone_id,
            'delivery_fee'              => (float) ($this->delivery_fee ?? 0),
            'delivery_address_snapshot' => $this->delivery_address_snapshot,
            'delivery_notes'            => $this->delivery_notes,
            'tax_rate'                  => (float) ($this->tax_rate ?? 0),
            'tax_amount'                => (float) ($this->tax_amount ?? 0),
            'scheduled_at'              => $this->scheduled_at?->toIso8601String(),
            'cancellation_reason'       => $this->cancellation_reason,
            'cancelled_at'              => $this->cancelled_at?->toIso8601String(),

            // مستقلة تمامًا عن حالة الطلب أعلاه — الدفع وحده لا يغلق الطلب أبدًا (راجع
            // CallCenterService::derivePaymentStatus لتفاصيل القاعدة). is_closed الفعلي يُحسم
            // بالفرونت عبر نفس القاعدة (determineOrderLifecycle) لما invoice يكون محمّلاً.
            'payment_status' => CallCenterService::derivePaymentStatus($invoiceStatus),

            'items'   => OrderItemResource::collection($this->whenLoaded('items')),
            'invoice' => $this->whenLoaded('invoice', fn () => new InvoiceResource($this->invoice)),
            'tickets' => ProductionTicketResource::collection($this->whenLoaded('tickets')),
            'branch'  => $this->whenLoaded('branch', fn() => $this->branch ? [
                'id'      => $this->branch->id,
                'name'    => $this->branch->name,
                'phone'   => $this->branch->phone,
                'address' => $this->branch->address,
            ] : null),
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
