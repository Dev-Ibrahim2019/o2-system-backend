<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $itemId = $this->route('item')->id;

        return [
            'department_id'          => 'sometimes|integer|exists:departments,id',
            'name'                   => 'sometimes|string|max:255',
            'name_ar'                => 'nullable|string|max:255',
            'code'                   => "sometimes|string|max:255|unique:items,code,{$itemId}",
            'image'                  => 'nullable|string|max:500',
            'unit'                   => 'nullable|string|max:50',
            'is_active'              => 'boolean',

            // الفروع — إذا أُرسلت نعالجها، إذا لم تُرسل نتجاهلها
            'branches'               => 'nullable|array',
            'branches.*.branch_id'   => 'required|integer|exists:branches,id',
            'branches.*.price'       => 'required|numeric|min:0',
            'branches.*.is_active'   => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'department_id.exists'          => 'القسم المحدد غير موجود',
            'code.unique'                   => 'كود الصنف مستخدم مسبقاً',
            'branches.*.branch_id.exists'   => 'أحد الفروع المحددة غير موجود',
            'branches.*.price.required'     => 'سعر الصنف في الفرع مطلوب',
            'branches.*.price.numeric'      => 'السعر يجب أن يكون رقماً',
            'branches.*.price.min'          => 'السعر لا يمكن أن يكون سالباً',
        ];
    }
}
