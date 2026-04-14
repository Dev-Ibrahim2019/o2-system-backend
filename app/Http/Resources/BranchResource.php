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
            'id'           => $this->id,
            'name'         => $this->name,
            'location'     => $this->location,
            'is_active'    => $this->is_active,
            'created_at'   => $this->created_at->toDateTimeString(),
            // العلاقات — تُضاف فقط إذا محمّلة
            'departments' => DepartmentResource::collection($this->whenLoaded('departments')),
        ];
    }
}
