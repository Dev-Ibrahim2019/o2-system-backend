<?php

namespace App\Http\Resources\AccountingResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'code'           => $this->code,
            'type'           => $this->type,
            'type_label'     => $this->getTypeLabel(),
            'normal_balance' => $this->normal_balance,
            'level'          => $this->level,
            'allow_posting'  => (bool) $this->allow_posting,
            'is_active'      => (bool) $this->is_active,
            'is_system'      => (bool) $this->is_system,
            'is_parent'      => (bool) $this->is_parent,
            'notes'          => $this->notes,

            // الرصيد — يُحسب فقط عند الطلب (لا في القائمة لأنه ثقيل)
            'balance'        => $this->when(
                $request->boolean('with_balance'),
                fn() => $this->balance
            ),
            'total_debit'    => $this->when(
                $request->boolean('with_balance'),
                fn() => $this->total_debit
            ),
            'total_credit'   => $this->when(
                $request->boolean('with_balance'),
                fn() => $this->total_credit
            ),

            // العلاقات
            'parent'    => $this->whenLoaded('parent', fn() => [
                'id'   => $this->parent->id,
                'name' => $this->parent->name,
                'code' => $this->parent->code,
            ]),
            'children'  => AccountResource::collection($this->whenLoaded('children')),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function getTypeLabel(): string
    {
        return match ($this->type) {
            'asset'     => 'أصول',
            'liability' => 'التزامات',
            'equity'    => 'حقوق ملكية',
            'revenue'   => 'إيرادات',
            'expense'   => 'مصاريف',
            default     => $this->type,
        };
    }
}
