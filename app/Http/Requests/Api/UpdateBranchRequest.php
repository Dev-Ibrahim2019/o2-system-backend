<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'         => 'sometimes|string|max:255',
            'address'      => 'nullable|string|max:255',
            'phone'        => 'nullable|string|max:20',
            'is_active'    => 'boolean',
            'code'         => 'sometimes|string|max:255|unique:branches,code,' . $this->route('branch')->id,
            'isMainBranch' => 'sometimes|boolean',
            'closingTime'  => 'sometimes|date_format:H:i',
            'openingTime'  => 'sometimes|date_format:H:i',
        ];
    }
}
