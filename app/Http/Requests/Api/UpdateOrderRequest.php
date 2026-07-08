<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_type' => 'sometimes|in:dine_in,takeaway,delivery',
            'table_number' => 'nullable|string|max:50',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'note' => 'nullable|string',
            'discount_value' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:amount,percent',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'employee_id' => 'nullable|integer|exists:employees,id',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'items' => 'nullable|array',
            'items.*.item_id' => 'required|integer|exists:items,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.notes' => 'nullable|string|max:500',
        ];
    }
}
