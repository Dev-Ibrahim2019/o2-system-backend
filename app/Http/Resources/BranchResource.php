<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'address'       => $this->address,
            'is_active'     => $this->is_active,
            'code'          => $this->code,
            'phone'         => $this->phone,
            'isMainBranch'  => $this->isMainBranch,
            'closingTime'   => $this->closingTime,
            'openingTime'   => $this->openingTime,

            'created_at'    => $this->created_at->toDateTimeString(),
            // العلاقات — تُضاف فقط إذا محمّلة
            'departments' => DepartmentResource::collection($this->whenLoaded('departments')),
            'items'       => ItemResource::collection($this->whenLoaded('items')),
            'items'       => ItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
