<?php

namespace App\Http\Requests\Api\Accounting;

use Illuminate\Foundation\Http\FormRequest;

class RecordAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount'          => 'required|numeric|min:0.01',
            'cash_account_id' => 'required|exists:accounts,id',
            'date'            => 'required|date',
            'description'     => 'nullable|string|max:255',
            'branch_id'       => 'nullable|exists:branches,id',
        ];
    }
}
