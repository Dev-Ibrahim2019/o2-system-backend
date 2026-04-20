<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentItemResource extends JsonResource
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
            'branch_id'     => $this->branch_id,
            'department_id' => $this->department_id,
            'item_id'       => $this->item_id,
            'role'          => $this->role,
            'price'         => $this->price,
            'is_active'     => $this->is_active,
            'item'          => new ItemResource($this->whenLoaded('item')),
            'department'    => new DepartmentResource($this->whenLoaded('department')),
            'branch'        => new BranchResource($this->whenLoaded('branch')),
        ];
    }
}
