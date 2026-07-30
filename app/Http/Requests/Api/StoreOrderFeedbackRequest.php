<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'food_quality' => ['required', 'integer', 'between:1,5'],
            'service_quality' => ['required', 'integer', 'between:1,5'],
            'delivery_speed' => [
                $this->route('order')?->order_type === 'delivery' ? 'required' : 'nullable',
                'integer',
                'between:1,5',
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
