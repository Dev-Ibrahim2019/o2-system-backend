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
            'nameAr'              => $this->nameAr,
            'shortName'           => $this->shortName,
            'icon'                => $this->icon,
            'color'               => $this->color,
            'type'                => $this->type,
            'status'              => $this->status,
            'location'            => $this->location,
            'stationNumber'       => $this->stationNumber,
            'defaultPrepTime'     => $this->defaultPrepTime,
            'maxConcurrentOrders' => $this->maxConcurrentOrders,
            'hasKds'              => $this->hasKds,
            'autoPrintTicket'     => $this->autoPrintTicket,
        ];
    }
}
