<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'department_id'          => 'required|integer|exists:departments,id',
            'name'                   => 'required|string|max:255',
            'name_ar'                => 'nullable|string|max:255',
            'code'                   => 'required|string|max:255|unique:items,code',
            'image'                  => 'nullable|string|max:500',
            'unit'                   => 'nullable|string|max:50',
            'is_active'              => 'boolean',

            // الفروع — اختيارية عند الإنشاء
            'branches'               => 'nullable|array',
            'branches.*.branch_id'   => 'required|integer|exists:branches,id',
            'branches.*.price'       => 'required|numeric|min:0',
            'branches.*.is_active'   => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'department_id.required'        => 'القسم مطلوب',
            'department_id.exists'          => 'القسم المحدد غير موجود',
            'name.required'                 => 'الاسم بالإنجليزي مطلوب',
            'name_ar.required'              => 'الاسم بالعربي مطلوب',
            'code.required'                 => 'كود الصنف مطلوب',
            'code.unique'                   => 'كود الصنف مستخدم مسبقاً',
            'branches.*.branch_id.exists'   => 'أحد الفروع المحددة غير موجود',
            'branches.*.price.required'     => 'سعر الصنف في الفرع مطلوب',
            'branches.*.price.numeric'      => 'السعر يجب أن يكون رقماً',
            'branches.*.price.min'          => 'السعر لا يمكن أن يكون سالباً',
        ];
    }
}
