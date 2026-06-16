<?php

namespace App\Http\Resources\AccountingResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'debit'       => (float) $this->debit,
            'credit'      => (float) $this->credit,
            'description' => $this->description,
            'sort_order'  => $this->sort_order,

            // ── Subledger ──────────────────────────────────────────────────
            // يُظهر الكيان المرتبط (موظف/عميل/مورد) إذا وُجد
            'subledger' => $this->when(
                $this->subledger_type !== null,
                fn() => [
                    'type' => $this->subledger_type,
                    'id'   => $this->subledger_id,
                    // الاسم يُجلب من الـ relation إذا كانت محمّلة
                    'name' => $this->getSubledgerName(),
                ]
            ),

            'account' => $this->whenLoaded('account', fn() => [
                'id'   => $this->account->id,
                'name' => $this->account->name,
                'code' => $this->account->code,
                'type' => $this->account->type,
            ]),

            'cost_center' => $this->whenLoaded('costCenter', fn() => $this->costCenter ? [
                'id'   => $this->costCenter->id,
                'name' => $this->costCenter->name,
                'code' => $this->costCenter->code,
            ] : null),
        ];
    }

    private function getSubledgerName(): ?string
    {
        if (! $this->subledger_type || ! $this->subledger_id) {
            return null;
        }

        // يُعيد الاسم من الـ relation المحمّلة إذا وُجدت
        // (يتم تحميلها في الـ Controller عند الحاجة)
        return match ($this->subledger_type) {
            'employee' => optional($this->whenLoaded('subledgerEmployee'))->name,
            'customer' => optional($this->whenLoaded('subledgerCustomer'))->name,
            'supplier' => optional($this->whenLoaded('subledgerSupplier'))->name,
            default    => null,
        };
    }
}
