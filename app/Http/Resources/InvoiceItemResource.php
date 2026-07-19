<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $originalPrice = $this->original_price !== null
            ? (float) $this->original_price
            : (float) $this->price;
        $finalPrice = $this->final_price !== null
            ? (float) $this->final_price
            : (float) $this->price;
        $quantity = (float) $this->quantity;
        $lineDiscount = (float) $this->discount_amount * $quantity;

        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'item_name' => $this->item_name,
            'quantity' => $quantity,
            'price' => (float) $this->price,
            'total' => (float) $this->total,
            'original_price' => $originalPrice,
            'discount_id' => $this->discount_id,
            'discount_name' => $this->whenLoaded('discountDetail', fn () => $this->discountDetail?->name),
            'discount_code' => $this->whenLoaded('discountDetail', fn () => $this->discountDetail?->code),
            'discount_type' => $this->whenLoaded('discountDetail', fn () => $this->discountDetail?->discount_type),
            'discount_value' => $this->whenLoaded('discountDetail', fn () => $this->discountDetail ? (float) $this->discountDetail->value : null),
            'discount_amount' => (float) $this->discount_amount,
            'discount_percent' => $this->discount_percent ? (float) $this->discount_percent : null,
            'final_price' => $finalPrice,
            'subtotal' => $this->subtotal ? (float) $this->subtotal : round($originalPrice * $quantity, 3),
            'line_discount' => $lineDiscount,
            'savings' => $lineDiscount,
            'discount' => $this->whenLoaded('discountDetail', function () {
                return [
                    'id' => $this->discountDetail->id,
                    'name' => $this->discountDetail->name,
                    'name_ar' => $this->discountDetail->name_ar,
                    'code' => $this->discountDetail->code,
                    'discount_type' => $this->discountDetail->discount_type,
                    'value' => (float) $this->discountDetail->value,
                ];
            }),
        ];
    }
}
