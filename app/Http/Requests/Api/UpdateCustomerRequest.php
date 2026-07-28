<?php

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends StoreCustomerRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('crm.edit-customers') ?? false;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'] = ['sometimes', 'required', 'string', 'max:255'];
        $rules['phone'] = ['nullable', 'string', 'max:30'];
        $rules['mobile'] = ['nullable', 'string', 'max:30'];
        $rules['code'] = [
            'nullable',
            'string',
            'max:50',
            Rule::unique('customers', 'code')->ignore($this->route('customer'))->whereNull('deleted_at'),
        ];
        unset($rules['opening_balance'], $rules['opening_balance_type'], $rules['opening_balance_date']);

        return $rules;
    }
}
