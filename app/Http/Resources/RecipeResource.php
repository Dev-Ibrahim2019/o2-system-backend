<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecipeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'yield_qty'   => $this->yield_qty,
            'yield_unit'  => $this->yield_unit,
            'notes'       => $this->notes,
            'output_item' => new ItemResource($this->whenLoaded('outputItem')),
            'ingredients' => RecipeIngredientResource::collection($this->whenLoaded('ingredients')),
            // تكلفة الوصفة — تُحسب فقط إذا branch_id موجود في الـ request
            'cost'        => $this->when(
                request()->filled('branch_id'),
                fn() => $this->calculateCost(request('branch_id'))
            ),
        ];
    }
}
