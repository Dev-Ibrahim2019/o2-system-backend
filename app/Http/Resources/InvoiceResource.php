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
            fn () => (float) $this->payments->sum('amount'),
            fn () => (float) $this->payments()->sum('amount')
        );

        $entityName = null;
        if ($this->entity_type && $this->entity_id) {
            $entityModel = match ($this->entity_type) {
                'customer' => \App\Models\Customer::find($this->entity_id),
                'employee' => \App\Models\Employee::find($this->entity_id),
                'supplier' => \App\Models\Supplier::find($this->entity_id),
                default => null,
            };
            $entityName = $entityModel?->name ?? $entityModel?->full_name ?? null;
        }

        $branchName = null;
        if ($this->branch_id) {
            $branchName = \App\Models\Branch::find($this->branch_id)?->name;
        }

        return [
            'id' => $this->id,
            'number' => $this->number,
            'type' => $this->type ?? 'فاتورة ضريبية',
            'order_id' => $this->order_id,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'entity_name' => $entityName,
            'branch_id' => $this->branch_id,
            'branch_name' => $branchName,
            'customer_id' => $this->customer_id,
            'status' => $this->status,
            'currency' => $this->currency ?? 'SAR',
            'payment_method' => $this->payment_method,
            'subtotal' => (float) $this->subtotal,
            'discount' => (float) $this->discount,
            'tax_total' => (float) ($this->tax_total ?? 0),
            'total' => (float) $this->total,
            'paid_amount' => $paidAmount,
            'remaining_amount' => max(0, (float) $this->total - $paidAmount),
            'invoice_date' => $this->invoice_date?->toIso8601String(),
            'due_date' => $this->due_date?->toIso8601String(),
            'delivery_date' => $this->delivery_date?->toIso8601String(),
            'expected_payment_date' => $this->expected_payment_date?->toIso8601String(),
            'notes' => $this->notes,
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
