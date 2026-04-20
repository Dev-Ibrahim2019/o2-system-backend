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
            'id'        => $this->id,
            'name'      => $this->name,
            'unit'      => $this->unit,
            'base_type' => $this->base_type,
            'is_active' => $this->is_active,
            'group'     => new ItemGroupResource($this->whenLoaded('group')),
        ];
    }
}
