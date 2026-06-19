<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => 'required|exists:branches,id',
            'cashier_id' => 'nullable|exists:employees,id',
            'order_type' => 'required|in:dine_in,takeaway,delivery',
            'table_number' => 'nullable|string|max:50',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'note' => 'nullable|string',
            'discount_value' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:amount,percent',

            // اختياري: إرسال الأصناف مع إنشاء الطلب (نفس تنسيق الواجهة القديمة)
            'items' => 'sometimes|array|min:1',
            'items.*.item_id' => 'required_with:items|exists:items,id',
            'items.*.quantity' => 'required_with:items|numeric|min:0.001',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.notes' => 'nullable|string',
        ];
    }
}
