<?php
// app/Http/Resources/DepartmentResource.php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'name'                => $this->name,
            'shortName'           => $this->shortName,
            'icon'                => $this->icon,
            'color'               => $this->color,
            'type'                => $this->type,
            'is_active'           => $this->is_active,
            'stationNumber'       => $this->stationNumber,
            'defaultPrepTime'     => $this->defaultPrepTime,
            'maxConcurrentOrders' => $this->maxConcurrentOrders,
            'hasKds'              => $this->hasKds,
            'autoPrintTicket'     => $this->autoPrintTicket,

            'branches' => BranchResource::collection($this->whenLoaded('branches')),
            'is_active_in_branch' => $this->when(
                isset($this->pivot),
                fn() => (bool) $this->pivot?->is_active
            ),
        ];
    }
}
