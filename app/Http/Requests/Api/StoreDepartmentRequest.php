<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                => 'required|string|max:255',
            'parent_id'           => 'nullable|integer|exists:departments,id',
            'type' => 'required|in:department,section,unit',
            'is_central'          => 'boolean',
            'is_active'           => 'boolean',
            'shortName'           => 'nullable|string|max:10',
            'icon'                => 'nullable|string',
            'color'               => 'nullable|string|max:20',
            'stationNumber'       => 'nullable|string',
            'defaultPrepTime'     => 'nullable|integer|min:0',
            'maxConcurrentOrders' => 'nullable|integer|min:1',
            'hasKds'              => 'boolean',
            'autoPrintTicket'     => 'boolean',
            'branch_ids'          => 'sometimes|array',
            'branch_ids.*'        => 'integer|exists:branches,id',
        ];
    }
}
