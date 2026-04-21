<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'name_ar'    => $this->name_ar,
            'code'       => $this->code,
            'image'      => $this->image,
            'unit'       => $this->unit,
            'is_active'  => $this->is_active,
            'department' => new DepartmentResource($this->whenLoaded('department')),
            'branches'   => BranchResource::collection($this->whenLoaded('branches')),
        ];
    }
}
