<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'department_id' => 'sometimes|integer|exists:departments,id',
            'name'          => 'sometimes|string|max:255',
            'name_ar'       => 'nullable|string|max:255',
            'code'          => 'sometimes|string|max:255|unique:items,code,' . $this->route('item')->id,
            'image'         => 'nullable|string|max:255',
            'unit'          => 'sometimes|string|max:50',
            'is_active'     => 'boolean',
        ];
    }
}