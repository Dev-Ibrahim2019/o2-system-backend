<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class AddPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'method' => 'required|in:cash,card,bank,wallet,account,mixed,customer,employee,supplier',
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string',
            'branch_id' => 'nullable|exists:branches,id',
            'payment_method_id' => 'nullable|exists:payment_methods,id',
            'reference_number' => 'nullable|string|max:255',
            'entity_type' => 'nullable|string|in:customer,employee,supplier',
            'entity_id' => 'nullable|integer|min:1',
            'subledger_type' => 'nullable|string|in:customer,employee,supplier',
            'subledger_id' => 'nullable|integer|min:1',
        ];
    }
}
