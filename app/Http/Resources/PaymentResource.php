<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'number' => $this->number,
            'method' => $this->method,
            'amount' => (float) $this->amount,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'notes' => $this->notes,
            'branch_id' => $this->branch_id,
            'user_id' => $this->user_id,
        ];
    }
}
