<?php

namespace App\Http\Requests\Api\Accounting;

use Illuminate\Foundation\Http\FormRequest;

class StoreCostCenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:255'],
            'code'      => ['nullable', 'string', 'max:50', 'unique:cost_centers,code'],
            'type'      => ['nullable', 'in:operational,administrative,service,production'],
            'parent_id' => ['nullable', 'integer', 'exists:cost_centers,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'is_active' => ['boolean'],
            'notes'     => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'      => 'اسم مركز التكلفة مطلوب',
            'code.unique'        => 'كود مركز التكلفة مستخدم مسبقاً',
            'parent_id.exists'   => 'مركز التكلفة الأب غير موجود',
            'branch_id.exists'   => 'الفرع غير موجود',
        ];
    }
}
