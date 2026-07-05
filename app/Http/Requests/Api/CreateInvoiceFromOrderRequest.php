<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CreateInvoiceFromOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => 'nullable|integer',
            'employee_id' => 'nullable|integer|exists:employees,id',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'notes' => 'nullable|string',
        ];
    }
}
