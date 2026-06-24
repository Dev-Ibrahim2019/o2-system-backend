<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $paidAmount = $this->whenLoaded(
            'payments',
            fn() => (float) $this->payments->sum('amount'),
            fn() => (float) $this->payments()->sum('amount')
        );

        return [
            'id' => $this->id,
            'number' => $this->number,
            'order_id' => $this->order_id,
            'branch_id' => $this->branch_id,
            'customer_id' => $this->customer_id,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'payment_method_display' => $this->payment_method_display,
            'subtotal' => (float) $this->subtotal,
            'discount' => (float) $this->discount,
            'total' => (float) $this->total,
            'paid_amount' => $paidAmount,
            'remaining_amount' => max(0, (float) $this->total - $paidAmount),
            'invoice_date' => $this->invoice_date?->toIso8601String(),
            'notes' => $this->notes,
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
