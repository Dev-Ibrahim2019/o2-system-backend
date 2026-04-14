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
        return [
            'name'                => ['required', 'string', 'max:255'],
            'nameAr'              => ['nullable', 'string', 'max:255'],
            'shortName'           => ['nullable', 'string', 'max:10'],
            'icon'                => ['nullable', 'string'],
            'color'               => ['nullable', 'string', 'max:20'],
            'type'                => ['nullable', 'in:KITCHEN,BAR,GRILL,PASTRY,OTHER'],
            'status'              => ['nullable', 'in:ACTIVE,BUSY,INACTIVE'],
            'location'            => ['nullable', 'string'],
            'stationNumber'       => ['nullable', 'string'],
            'defaultPrepTime'     => ['nullable', 'integer', 'min:0'],
            'maxConcurrentOrders' => ['nullable', 'integer', 'min:1'],
            'hasKds'              => ['nullable', 'boolean'],
            'autoPrintTicket'     => ['nullable', 'boolean'],
        ];
    }
}
