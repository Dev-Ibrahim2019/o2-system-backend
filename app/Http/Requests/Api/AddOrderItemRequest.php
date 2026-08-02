<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class AddOrderItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => 'required|exists:items,id',
            'quantity' => 'required|numeric|min:0.001',
            'notes' => 'nullable|string',
            // اختياري: تجاوز السعر من branch_item
            'unit_price' => 'nullable|numeric|min:0',
            'is_takeaway' => 'nullable|boolean',
        ];
    }
}
