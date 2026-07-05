<?php

namespace App\Http\Requests\Api\SalesInvoice;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalesInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in(['tax_invoice', 'simple_invoice', 'credit_note', 'debit_note'])],
            'status' => ['nullable', Rule::in(['draft', 'awaiting_approval'])],
            'tax_treatment' => ['nullable', Rule::in(['inclusive', 'exclusive'])],

            // Customer
            'customer_id' => ['nullable', 'exists:customers,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'customer_vat_number' => ['nullable', 'string', 'max:50'],

            // Dates
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'supply_date' => ['nullable', 'date'],

            // Financial
            'currency' => ['nullable', 'string', 'max:10'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'branch_id' => ['required', 'exists:branches,id'],

            // Reference
            'reference_number' => ['nullable', 'string', 'max:100'],

            // Notes
            'notes' => ['nullable', 'string', 'max:2000'],

            // Items (required, at least one)
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'exists:items,id'],
            'items.*.item_name' => ['required', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'items.*.account_id' => ['nullable', 'exists:accounts,id'],
            'items.*.branch_id' => ['nullable', 'exists:branches,id'],
            'items.*.tracking_name' => ['nullable', 'string', 'max:100'],
            'items.*.tracking_option' => ['nullable', 'string', 'max:100'],

            // Payments (optional on creation)
            'payments' => ['nullable', 'array'],
            'payments.*.method' => ['required_with:payments', Rule::in(['cash', 'credit_card', 'bank_transfer', 'app', 'account'])],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'min:0.01'],
            'payments.*.reference_number' => ['nullable', 'string', 'max:100'],
            'payments.*.paid_at' => ['nullable', 'date'],
            'payments.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'يجب إضافة صنف واحد على الأقل',
            'items.min' => 'يجب إضافة صنف واحد على الأقل',
            'items.*.item_name.required' => 'اسم الصنف مطلوب',
            'items.*.quantity.required' => 'الكمية مطلوبة',
            'items.*.unit_price.required' => 'سعر الوحدة مطلوب',
            'items.*.tax_rate.required' => 'نسبة الضريبة مطلوبة',
            'branch_id.required' => 'الفرع مطلوب',
            'invoice_date.required' => 'تاريخ الفاتورة مطلوب',
        ];
    }
}
