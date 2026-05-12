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
}
