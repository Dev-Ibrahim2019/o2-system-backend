<?php

namespace App\Http\Requests\Api\Accounting;

use Illuminate\Foundation\Http\FormRequest;

class RecordInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount'             => 'required|numeric|min:0.01',
            'offset_account_id'  => 'required|exists:accounts,id', // Sales or Expense account
            'date'               => 'required|date',
            'reference'          => 'nullable|string|max:100',
            'description'        => 'nullable|string|max:255',
            'branch_id'          => 'nullable|exists:branches,id',
        ];
    }
}
