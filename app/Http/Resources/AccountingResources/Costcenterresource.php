<?php

namespace App\Http\Resources\AccountingResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CostCenterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'name'      => $this->name,
            'code'      => $this->code,
            'type'      => $this->type,
            'type_label' => $this->getTypeLabel(),
            'is_active' => (bool) $this->is_active,
            'notes'     => $this->notes,

            'parent' => $this->whenLoaded('parent', fn() => $this->parent ? [
                'id'   => $this->parent->id,
                'name' => $this->parent->name,
            ] : null),

            'children' => CostCenterResource::collection($this->whenLoaded('children')),

            'branch' => $this->whenLoaded('branch', fn() => $this->branch ? [
                'id'   => $this->branch->id,
                'name' => $this->branch->name,
            ] : null),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function getTypeLabel(): string
    {
        return match ($this->type) {
            'operational'    => 'تشغيلي',
            'administrative' => 'إداري',
            'service'        => 'خدمي',
            'production'     => 'إنتاجي',
            default          => $this->type ?? '—',
        };
    }
}
