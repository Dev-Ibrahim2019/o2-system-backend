<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecipeIngredientResource extends JsonResource
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
            'recipe_id' => $this->recipe_id,
            'quantity'  => $this->quantity,
            'unit'      => $this->unit,
            'item'      => new ItemResource($this->whenLoaded('item')),
        ];
    }
}
