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
            // كانت هاي بتنرمى من $request->validated() رغم إنه الكنترولر
            // بيحاول يمررها لل service — فحساب/عملة الفاتورة تضل فاضية دايماً.
            'currency' => 'nullable|string|max:10',
            'account_number' => 'nullable|string|max:100',
            'reference_number' => 'nullable|string|max:100',
        ];
    }
}
