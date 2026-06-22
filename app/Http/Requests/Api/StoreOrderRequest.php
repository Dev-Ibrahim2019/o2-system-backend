<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->user();

        return [
            'branch_id' => [
                Rule::requiredIf(! $user?->branch_id),
                'nullable',
                'exists:branches,id',
            ],
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

    protected function prepareForValidation(): void
    {
        $orderType = $this->input('order_type') ?? $this->input('orderType');

        if (is_string($orderType)) {
            $orderType = match (strtoupper(str_replace('-', '_', $orderType))) {
                'DINE_IN', 'DINEIN' => 'dine_in',
                'TAKE_AWAY', 'TAKEAWAY' => 'takeaway',
                'DELIVERY' => 'delivery',
                default => strtolower($orderType),
            };
        }

        $items = collect($this->input('items', []))
            ->map(function ($item) {
                if (! is_array($item)) {
                    return $item;
                }

                if (! array_key_exists('item_id', $item) && array_key_exists('id', $item)) {
                    $item['item_id'] = $item['id'];
                }

                if (! array_key_exists('unit_price', $item) && array_key_exists('price', $item)) {
                    $item['unit_price'] = $item['price'];
                }

                if (! array_key_exists('notes', $item) && array_key_exists('note', $item)) {
                    $item['notes'] = $item['note'];
                }

                return $item;
            })
            ->all();

        $data = [
            'branch_id' => $this->input('branch_id') ?? $this->input('branchId'),
            'cashier_id' => $this->input('cashier_id') ?? $this->input('cashierId'),
            'order_type' => $orderType,
            'table_number' => $this->input('table_number') ?? $this->input('tableNumber'),
            'customer_name' => $this->input('customer_name') ?? $this->input('customerName'),
            'customer_phone' => $this->input('customer_phone') ?? $this->input('customerPhone'),
            'discount_value' => $this->input('discount_value') ?? $this->input('discountValue'),
            'discount_type' => $this->input('discount_type') ?? $this->input('discountType'),
        ];

        if ($this->has('items')) {
            $data['items'] = $items;
        }

        $this->merge($data);
    }
}
