<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiscountExclusionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'discount_id' => $this->discount_id,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'target_type_label' => $this->target_type,
            'target_name' => null,
        ];
    }
}
