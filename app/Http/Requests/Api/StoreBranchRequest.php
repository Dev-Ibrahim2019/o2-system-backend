<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'         => 'required|string|max:255',
            'address'      => 'nullable|string|max:255',
            'phone'        => 'nullable|string|max:20',
            'is_active'    => 'boolean',
            'code'         => 'required|string|max:255|unique:branches,code',
            'isMainBranch' => 'required|boolean',
            'closingTime'  => 'required|date_format:H:i',
            'openingTime'  => 'required|date_format:H:i',
        ];
    }
}
