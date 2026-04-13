<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemGroupResource extends JsonResource
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
            'parent_id' => $this->parent_id,
            'full_path' => $this->when($this->relationLoaded('parent'), fn() => $this->full_path),
            'children'  => ItemGroupResource::collection($this->whenLoaded('allChildren')),
            'items'     => ItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
