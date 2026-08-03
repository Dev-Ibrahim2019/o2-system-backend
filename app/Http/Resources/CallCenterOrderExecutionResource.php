<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CallCenterOrderExecutionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $invoice = $this->relationLoaded('invoice') ? $this->invoice : $this->invoice()->first();

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'order_status' => $this->status,
            'invoice_status' => $invoice?->status,
            'paid_amount' => $invoice?->paidAmount() ?? 0,
            'remaining_amount' => $invoice?->remainingAmount() ?? 0,
            'payment_policy' => $this->payment_policy,
            'payment_status' => $this->payment_status,
            'kitchen_release_status' => $this->kitchen_release_status,
            'kitchen_released_at' => $this->kitchen_released_at?->toIso8601String(),
            'kitchen_released_by' => $this->kitchen_released_by,
        ];
    }
}
