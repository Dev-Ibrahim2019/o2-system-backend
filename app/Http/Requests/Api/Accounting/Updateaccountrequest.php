<?php

namespace App\Http\Requests\Api\Accounting;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $accountId = $this->route('account')->id;

        return [
            'name'           => ['sometimes', 'string', 'max:255'],
            'code'           => ['sometimes', 'string', 'max:50', "unique:accounts,code,{$accountId}"],
            'type'           => ['sometimes', 'in:asset,liability,equity,revenue,expense'],
            'normal_balance' => ['nullable', 'in:debit,credit'],
            'parent_id'      => ['nullable', 'integer', 'exists:accounts,id'],
            'level'          => ['nullable', 'integer', 'min:1', 'max:5'],
            'allow_posting'  => ['boolean'],
            'is_active'      => ['boolean'],
            'notes'          => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique'      => 'كود الحساب مستخدم مسبقاً',
            'type.in'          => 'نوع الحساب غير صحيح',
            'parent_id.exists' => 'الحساب الأب غير موجود',
        ];
    }
}
