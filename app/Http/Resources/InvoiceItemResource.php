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

        $discountModel = $this->relationLoaded('discount')
            ? $this->getRelation('discount')
            : null;

        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'item_name' => $this->item_name,
            'quantity' => $quantity,
            'price' => (float) $this->price,
            'total' => (float) $this->total,
            'original_price' => $originalPrice,
            'discount_id' => $this->discount_id,
            'discount_name' => $discountModel?->name,
            'discount_code' => $discountModel?->code,
            'discount_type' => $discountModel?->discount_type,
            'discount_value' => $discountModel ? (float) $discountModel->value : null,
            'discount_amount' => (float) $this->discount_amount,
            'discount_percent' => $this->discount_percent ? (float) $this->discount_percent : null,
            'final_price' => $finalPrice,
            'subtotal' => $this->subtotal ? (float) $this->subtotal : round($originalPrice * $quantity, 3),
            'line_discount' => $lineDiscount,
            'savings' => $lineDiscount,
            'discount' => $discountModel ? [
                'id' => $discountModel->id,
                'name' => $discountModel->name,
                'name_ar' => $discountModel->name_ar,
                'code' => $discountModel->code,
                'discount_type' => $discountModel->discount_type,
                'value' => (float) $discountModel->value,
            ] : null,
        ];
    }
}
