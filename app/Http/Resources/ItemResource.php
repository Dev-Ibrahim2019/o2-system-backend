<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'name_ar'    => $this->name_ar,
            'code'       => $this->code,
            'image'      => $this->image,
            'image_url'  => $this->image_url,
            'unit'       => $this->unit,
            'is_active'  => $this->is_active,
            'pivot'      => $this->whenPivotLoaded('branch_item', fn() => [
                'price'     => $this->pivot->price,
                'is_active' => (bool) $this->pivot->is_active,
            ]),
            'department' => new DepartmentResource($this->whenLoaded('department')),
            'branches'   => BranchResource::collection($this->whenLoaded('branches')),
        ];
    }
}
