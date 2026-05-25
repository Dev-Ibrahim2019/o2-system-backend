<?php

namespace App\Http\Requests\Api\Accounting;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCostCenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('cost_center')->id;

        return [
            'name'      => ['sometimes', 'string', 'max:255'],
            'code'      => ['sometimes', 'nullable', 'string', 'max:50', "unique:cost_centers,code,{$id}"],
            'type'      => ['nullable', 'in:operational,administrative,service,production'],
            'parent_id' => ['nullable', 'integer', 'exists:cost_centers,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'is_active' => ['boolean'],
            'budget'    => ['nullable', 'numeric', 'min:0'],
            'notes'     => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'budget.numeric'     => 'الميزانية يجب أن تكون رقماً',
            'budget.min'         => 'الميزانية لا يمكن أن تكون سالبة',
        ];
    }
}
