<?php

namespace App\Http\Requests\Api;

class QuickCreateCustomerRequest extends StoreCustomerRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'address_name' => ['nullable', 'string', 'max:100'],
            'street' => ['nullable', 'string', 'max:255'],
            'building' => ['nullable', 'string', 'max:100'],
            'floor' => ['nullable', 'string', 'max:50'],
            'apartment' => ['nullable', 'string', 'max:50'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'birth_date' => ['nullable', 'date'],
        ];
    }
}
