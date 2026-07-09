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

        // ط¬ظ…ط¹ ظ…ط¹ظ„ظˆظ…ط§طھ ط§ظ„ط®طµظ… ظ…ظ† ط¨ظ†ظˆط¯ ط§ظ„ظپط§طھظˆط±ط©
        $appliedDiscount = null;
        if ($this->relationLoaded('items')) {
            $discountItem = $this->items->first(function ($item) {
                return $item->discount_id !== null;
            });
            $discountRelation = $discountItem && $discountItem->relationLoaded('discount')
                ? $discountItem->getRelation('discount')
                : null;
            if ($discountRelation) {
                $appliedDiscount = [
                    'id' => $discountRelation->id,
                    'name' => $discountRelation->name,
                    'name_ar' => $discountRelation->name_ar,
                    'code' => $discountRelation->code,
                    'discount_type' => $discountRelation->discount_type,
                    'value' => (float) $discountRelation->value,
                ];
            }
        }

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
            'type' => $this->type ?? 'ظپط§طھظˆط±ط© ط¶ط±ظٹط¨ظٹط©',
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
            'payment_method_display' => $this->payment_method_display,
            'subtotal' => (float) $this->subtotal,
            'discount' => (float) $this->discount,
            'tax_total' => (float) ($this->tax_total ?? 0),
            'total' => (float) $this->total,
            'paid_amount' => $paidAmount,
            'remaining_amount' => max(0, (float) $this->total - $paidAmount),
            'discount_info' => $appliedDiscount,
            'invoice_date' => $this->invoice_date?->toIso8601String(),
            'due_date' => $this->due_date?->toIso8601String(),
            'delivery_date' => $this->delivery_date?->toIso8601String(),
            'expected_payment_date' => $this->expected_payment_date?->toIso8601String(),
            'notes' => $this->notes,
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
            //  ًں–¥ï¸ڈ طھظپط§طµظٹظ„ ظ†ظ‚ط·ط© ط§ظ„ط¨ظٹط¹ (POS Details)
            // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
            'pos' => [
                'register_id' => $this->pos_register_id,
                'code'        => $this->pos_code,
                'name'        => $this->pos_name,
                'branch'      => $this->whenLoaded('branch', fn () => [
                    'id'   => $this->branch?->id,
                    'name' => $this->branch?->name,
                ]),
            ],

            // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
            //  ًں“‹ طھظپط§طµظٹظ„ ط§ظ„ظپط§طھظˆط±ط© (Invoice Details)
            // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
            'details' => [
                'number'         => $this->number,
                'date'           => $this->invoice_date?->format('Y-m-d'),
                'time'           => $this->invoice_date?->format('H:i:s'),
                'currency'       => $this->currency ?? 'ILS',
                'account_number' => $this->account_number,
            ],

            // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
            //  ًں”“ طھظپط§طµظٹظ„ ظپطھط­ ط§ظ„ظپط§طھظˆط±ط© (Open Details)
            // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
            'opening' => [
                'user'       => $this->whenLoaded('openedByUser', fn () => [
                    'id'   => $this->openedByUser?->id,
                    'name' => $this->openedByUser?->name,
                ]),
                'pos_name'   => $this->pos_name,
                'date'       => $this->opened_at?->format('Y-m-d'),
                'time'       => $this->opened_at?->format('H:i:s'),
            ],

            // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
            //  ًں”’ طھظپط§طµظٹظ„ ط¥ط؛ظ„ط§ظ‚ ط§ظ„ظپط§طھظˆط±ط© (Close Details)
            // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
            'closing' => $this->closed_at ? [
                'user'       => $this->whenLoaded('closedByUser', fn () => [
                    'id'   => $this->closedByUser?->id,
                    'name' => $this->closedByUser?->name,
                ]),
                'pos_name'   => $this->pos_name,
                'date'       => $this->closed_at?->format('Y-m-d'),
                'time'       => $this->closed_at?->format('H:i:s'),
            ] : null,
        ];
    }
}
