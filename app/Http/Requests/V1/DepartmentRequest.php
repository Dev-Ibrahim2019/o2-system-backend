<?php
// app/Http/Requests/V1/DepartmentRequest.php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class DepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? ['required'] : ['sometimes'];

        return [
            'name'                => [...$required, 'string', 'max:255'],
            'shortName'           => ['nullable', 'string', 'max:10'],
            'icon'                => ['nullable', 'string'],
            'color'               => ['nullable', 'string', 'max:20'],
            'type'                => [...$required, 'in:sale,production,storage'],
            'is_active'           => ['nullable', 'boolean'],
            'stationNumber'       => ['nullable', 'string'],
            'defaultPrepTime'     => ['nullable', 'integer', 'min:0'],
            'maxConcurrentOrders' => ['nullable', 'integer', 'min:1'],
            'hasKds'              => ['nullable', 'boolean'],
            'autoPrintTicket'     => ['nullable', 'boolean'],
            'branch_ids'          => ['sometimes', 'array'],
            'branch_ids.*'        => ['integer', 'exists:branches,id'],
        ];
    }
}
