<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'name_ar'       => $this->name_ar,
            'code'          => $this->code,
            'image'         => $this->image,
            'unit'          => $this->unit,
            'is_active'     => (bool) $this->is_active,
            'department_id' => $this->department_id,

            'department' => $this->whenLoaded('department', fn() => [
                'id'     => $this->department->id,
                'name'   => $this->department->name,
                'nameAr' => $this->department->nameAr ?? $this->department->name,
                'color'  => $this->department->color,
            ]),

            // الفروع مع بيانات الـ pivot (السعر والحالة لكل فرع)
            'branches' => $this->whenLoaded(
                'branches',
                fn() =>
                $this->branches->map(fn($branch) => [
                    'id'        => $branch->id,
                    'name'      => $branch->name,
                    'code'      => $branch->code,
                    'is_active' => (bool) $branch->is_active,
                    'pivot'     => [
                        'price'     => (float) $branch->pivot->price,
                        'is_active' => (bool)  $branch->pivot->is_active,
                    ],
                ])
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
