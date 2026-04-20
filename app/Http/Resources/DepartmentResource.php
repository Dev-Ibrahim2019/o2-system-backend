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
            'nameAr'              => $this->name,           // ← الفرونت يستخدم nameAr
            'shortName'           => $this->shortName,
            'icon'                => $this->icon,
            'color'               => $this->color,
            'type'                => $this->type,

            // ← تحويل is_active boolean → status string
            'status'              => $this->is_active ? 'ACTIVE' : 'INACTIVE',

            'location'            => $this->stationNumber ? "Station {$this->stationNumber}" : null,
            'stationNumber'       => $this->stationNumber,
            'defaultPrepTime'     => $this->defaultPrepTime,
            'maxConcurrentOrders' => $this->maxConcurrentOrders,
            'hasKds'              => (bool) $this->hasKds,
            'autoPrintTicket'     => (bool) $this->autoPrintTicket,

            // حقول يحتاجها types.ts في الفرونت
            'displayOrder'        => 0,
            'priority'            => 1,
            'requiresAssembly'    => false,
            'notifications'       => ['sound' => true, 'flash' => false, 'push' => true],
            'orderTypeVisibility' => ['DINE_IN', 'TAKEAWAY', 'DELIVERY'],
            'branchId'            => null,

            'branches' => BranchResource::collection($this->whenLoaded('branches')),
        ];
    }
}