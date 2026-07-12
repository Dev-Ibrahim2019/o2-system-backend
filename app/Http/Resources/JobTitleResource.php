<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobTitleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'name'      => $this->name,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'description' => $this->description,
            'department_id' => $this->department_id,
            'department' => $this->when($this->relationLoaded('department') && $this->department, fn () => ['id' => $this->department->id, 'name' => $this->department->name]),
            'default_operational_role' => $this->default_operational_role,
            'requires_vehicle' => (bool) $this->requires_vehicle,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
