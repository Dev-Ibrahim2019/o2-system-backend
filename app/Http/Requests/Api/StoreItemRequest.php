<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'department_id' => 'required|integer|exists:departments,id',
            'name'          => 'required|string|max:255',
            'name_ar'       => 'nullable|string|max:255',
            'code'          => 'required|string|max:255|unique:items,code',
            'image'         => 'nullable|string|max:255',
            'unit'          => 'nullable|string|max:50',
            'is_active'     => 'boolean',
        ];
    }
}