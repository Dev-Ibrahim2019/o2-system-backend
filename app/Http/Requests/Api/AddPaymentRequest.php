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
            'method' => 'required|in:cash,card,bank,wallet,account,mixed',
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string',
            'branch_id' => 'nullable|exists:branches,id',
        ];
    }
}
