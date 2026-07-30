<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCallCenterOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'call_ticket_id' => ['nullable', 'integer', 'exists:call_tickets,id'],
            'external_call_id' => ['nullable', 'string', 'max:255'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'order_type' => ['required', Rule::in(['delivery', 'takeaway'])],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'customer.name' => ['required_without:customer_id', 'nullable', 'string', 'max:255'],
            'customer.phone' => ['required_without:customer_id', 'nullable', 'string', 'max:40'],
            'address.customer_address_id' => ['nullable', 'integer', 'exists:customer_addresses,id'],
            'address.label' => ['nullable', 'string', 'max:50'],
            'address.city' => ['nullable', 'string', 'max:100'],
            'address.area' => ['nullable', 'string', 'max:100'],
            'address.street' => ['nullable', 'string', 'max:255'],
            'address.landmark' => ['nullable', 'string', 'max:255'],
            'address.delivery_notes' => ['nullable', 'string', 'max:4000'],
            'delivery_zone_id' => ['nullable', 'integer'],
            'delivery_fee' => ['nullable', 'numeric', 'min:0'],
            'delivery_address_snapshot' => ['nullable', 'array'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'discount_type' => ['nullable', Rule::in(['amount', 'percent'])],
            'notes' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
