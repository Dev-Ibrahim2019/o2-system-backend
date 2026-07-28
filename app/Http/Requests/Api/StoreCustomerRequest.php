<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('crm.create-customers') ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:50', Rule::unique('customers', 'code')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'required_without:mobile'],
            'mobile' => ['nullable', 'string', 'max:30', 'required_without:phone'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'area' => ['nullable', 'string', 'max:100'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', Rule::in(['retail', 'wholesale', 'vip', 'corporate', 'government', 'service', 'regular', 'important', 'new', 'inactive', 'follow_up', 'complaints'])],
            'currency' => ['nullable', 'string', 'size:3'],
            'risk_level' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'payment_terms' => ['nullable', Rule::in(['immediate', 'net15', 'net30', 'net60', 'net90', 'net_7', 'net_15', 'net_30', 'net_45', 'net_60', 'due_on_receipt'])],
            'credit_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'opening_balance' => ['nullable', 'numeric'],
            'opening_balance_type' => ['nullable', Rule::in(['debit', 'credit'])],
            'opening_balance_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'gps_link' => ['nullable', 'string', 'max:500'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'salesperson_id' => ['nullable', 'integer', 'exists:employees,id'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'blocked'])],
        ];
    }

    protected function passedValidation(): void
    {
        $financial = ['credit_limit', 'payment_terms', 'opening_balance', 'opening_balance_type', 'opening_balance_date'];
        if (collect($financial)->contains(fn (string $key) => $this->filled($key))
            && ! $this->user()?->can('crm.manage-customer-credit')) {
            abort(403, 'You are not authorized to manage customer credit.');
        }

        $data = $this->validated();
        if (($data['category'] ?? null) === 'regular') {
            $data['category'] = 'retail';
        }
        if (($data['payment_terms'] ?? null) === 'due_on_receipt') {
            $data['payment_terms'] = 'immediate';
        }
        $this->replace($data);
    }
}
