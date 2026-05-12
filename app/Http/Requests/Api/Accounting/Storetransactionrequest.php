<?php

namespace App\Http\Requests\Api\Accounting;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date'        => ['required', 'date'],
            'type'        => ['required', 'in:sale,purchase,salary,expense,receipt,payment,journal,opening,adjustment'],
            'reference'   => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'branch_id'   => ['nullable', 'integer', 'exists:branches,id'],

            'notes'       => ['nullable', 'string'],

            // سطور القيد — لازم يكون في على الأقل سطرين
            'entries'                    => ['required', 'array', 'min:2'],
            'entries.*.account_id'       => ['required', 'integer', 'exists:accounts,id'],
            'entries.*.debit'            => ['required', 'numeric', 'min:0'],
            'entries.*.credit'           => ['required', 'numeric', 'min:0'],
            'entries.*.description'      => ['nullable', 'string', 'max:255'],
            'entries.*.cost_center_id'   => ['nullable', 'integer', 'exists:cost_centers,id'],
            'entries.*.sort_order'       => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.required'                  => 'تاريخ القيد مطلوب',
            'type.required'                  => 'نوع القيد مطلوب',
            'type.in'                        => 'نوع القيد غير صحيح',
            'entries.required'               => 'سطور القيد مطلوبة',
            'entries.min'                    => 'القيد يحتاج سطرين على الأقل',
            'entries.*.account_id.required'  => 'الحساب مطلوب لكل سطر',
            'entries.*.account_id.exists'    => 'أحد الحسابات غير موجود',
            'entries.*.debit.required'       => 'قيمة المدين مطلوبة',
            'entries.*.credit.required'      => 'قيمة الدائن مطلوبة',
            'entries.*.cost_center_id.exists' => 'مركز التكلفة غير موجود',
        ];
    }

    /**
     * التحقق من توازن القيد (مجموع مدين = مجموع دائن)
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $entries = $this->input('entries', []);

            $totalDebit  = collect($entries)->sum('debit');
            $totalCredit = collect($entries)->sum('credit');

            // تحقق من أن كل سطر إما مدين أو دائن (ليس الاثنين معاً وليس صفراً)
            foreach ($entries as $index => $entry) {
                $debit  = (float) ($entry['debit']  ?? 0);
                $credit = (float) ($entry['credit'] ?? 0);

                if ($debit > 0 && $credit > 0) {
                    $validator->errors()->add(
                        "entries.{$index}",
                        "السطر رقم " . ($index + 1) . ": لا يمكن أن يكون السطر مديناً ودائناً في آن واحد"
                    );
                }

                if ($debit == 0 && $credit == 0) {
                    $validator->errors()->add(
                        "entries.{$index}",
                        "السطر رقم " . ($index + 1) . ": المبلغ يجب أن يكون أكبر من صفر"
                    );
                }
            }

            // تحقق من التوازن
            if (abs($totalDebit - $totalCredit) > 0.001) {
                $validator->errors()->add(
                    'entries',
                    "القيد غير متوازن — مجموع المدين: {$totalDebit}، مجموع الدائن: {$totalCredit}"
                );
            }
        });
    }
}
