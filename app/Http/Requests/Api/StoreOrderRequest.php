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
                'nullable',
                'integer',
            ],
            'cashier_id' => 'nullable|exists:employees,id',
            'dining_table_id' => 'nullable|integer|exists:dining_tables,id',
            'call_center_agent_id' => 'nullable|exists:users,id',
            'order_type' => 'required|in:dine_in,takeaway,delivery',
            'source' => 'nullable|string|max:50',
            'table_number' => 'nullable|string|max:50',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'customer_mobile' => 'nullable|string|max:30',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'customer_address_id' => 'nullable|integer|exists:customer_addresses,id',
            'delivery_zone_id' => 'nullable|integer|exists:delivery_zones,id',
            'delivery_fee' => 'nullable|numeric|min:0',
            'delivery_address_snapshot' => 'nullable|json',
            'customer_notes' => 'nullable|string',
            'delivery_notes' => 'nullable|string',
            'call_notes' => 'nullable|string',
            'needs_attention' => 'nullable|boolean',
            'customer_service_flag' => 'nullable|boolean',
            'employee_id' => 'nullable|integer|exists:employees,id',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'note' => 'nullable|string',
            'discount_value' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:amount,percent',
            'customer_id' => 'nullable|integer',
            'employee_id' => 'nullable|integer',
            'supplier_id' => 'nullable|integer',

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
            'call_center_agent_id' => $this->input('call_center_agent_id') ?? $this->input('callCenterAgentId'),
            'order_type' => $orderType,
            'dining_table_id' => $this->input('dining_table_id') ?? $this->input('diningTableId'),
            'source' => $this->input('source', 'pos'),
            'table_number' => $this->input('table_number') ?? $this->input('tableNumber'),
            'customer_name' => $this->input('customer_name') ?? $this->input('customerName'),
            'customer_phone' => $this->input('customer_phone') ?? $this->input('customerPhone'),
            'customer_mobile' => $this->input('customer_mobile') ?? $this->input('customerMobile'),
            'customer_id' => $this->input('customer_id') ?? $this->input('customerId'),
            'customer_address_id' => $this->input('customer_address_id') ?? $this->input('customerAddressId'),
            'delivery_zone_id' => $this->input('delivery_zone_id') ?? $this->input('deliveryZoneId'),
            'delivery_fee' => $this->input('delivery_fee') ?? $this->input('deliveryFee'),
            'delivery_address_snapshot' => $this->input('delivery_address_snapshot') ?? $this->input('deliveryAddressSnapshot'),
            'customer_notes' => $this->input('customer_notes') ?? $this->input('customerNotes'),
            'delivery_notes' => $this->input('delivery_notes') ?? $this->input('deliveryNotes'),
            'call_notes' => $this->input('call_notes') ?? $this->input('callNotes'),
            'needs_attention' => $this->input('needs_attention') ?? $this->input('needsAttention'),
            'customer_service_flag' => $this->input('customer_service_flag') ?? $this->input('customerServiceFlag'),
            'discount_value' => $this->input('discount_value') ?? $this->input('discountValue'),
            'discount_type' => $this->input('discount_type') ?? $this->input('discountType'),
            'employee_id' => $this->input('employee_id') ?? $this->input('employeeId'),
            'supplier_id' => $this->input('supplier_id') ?? $this->input('supplierId'),
        ];

        if ($this->has('items')) {
            $data['items'] = $items;
        }

        $this->merge($data);
    }
}