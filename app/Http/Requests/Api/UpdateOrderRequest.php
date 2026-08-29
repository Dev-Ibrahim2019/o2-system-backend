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
            'status' => 'sometimes|in:pending,confirmed,in_progress,ready,served,paid,cancelled,pending_payment',
            'table_number' => 'nullable|string|max:50',
            'payment_method' => 'nullable|string|max:50',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'customer_mobile' => 'nullable|string|max:30',
            'customer_address' => 'nullable|string|max:255',
            'customer_notes' => 'nullable|string',
            'scheduled_at' => 'nullable|date',
            'currency' => 'nullable|string|max:8',
            'exchange_rate' => 'nullable|numeric|min:0',
            'note' => 'nullable|string',
            'discount_value' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:amount,percent',
            'customer_id' => 'nullable|integer',
            'employee_id' => 'nullable|integer',
            'supplier_id' => 'nullable|integer',
            'items' => 'nullable|array',
            'items.*.item_id' => 'required|integer|exists:items,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.notes' => 'nullable|string|max:500',
            'items.*.is_takeaway' => 'nullable|boolean',
            'items.*.is_complimentary' => 'nullable|boolean',
            'skip_sync' => 'nullable|boolean',
        ];
    }
}
