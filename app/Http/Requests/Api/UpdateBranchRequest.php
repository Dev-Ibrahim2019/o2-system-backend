<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge($this->normalizedBranchInput());
    }

    public function rules(): array
    {
        return [
            'name'         => 'sometimes|string|max:255',
            'address'      => 'nullable|string|max:255',
            'phone'        => 'nullable|string|max:20',
            'is_active'    => 'boolean',
            'code'         => 'sometimes|string|max:255|unique:branches,code,' . $this->route('branch')->id,
            'isMainBranch' => 'sometimes|boolean',
            'closingTime'  => 'sometimes|nullable|date_format:H:i',
            'openingTime'  => 'sometimes|nullable|date_format:H:i',
        ];
    }

    private function normalizedBranchInput(): array
    {
        $input = $this->all();

        $aliases = [
            'isActive'       => 'is_active',
            'is_main_branch' => 'isMainBranch',
            'opening_time'   => 'openingTime',
            'closing_time'   => 'closingTime',
        ];

        foreach ($aliases as $from => $to) {
            if (array_key_exists($from, $input) && !array_key_exists($to, $input)) {
                $input[$to] = $input[$from];
            }
        }

        foreach (['address', 'phone', 'openingTime', 'closingTime'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] === '') {
                $input[$field] = null;
            }
        }

        foreach (['is_active', 'isMainBranch'] as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = filter_var($input[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $input[$field];
            }
        }

        foreach (['openingTime', 'closingTime'] as $field) {
            if (is_string($input[$field] ?? null) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $input[$field])) {
                $input[$field] = Str::of($input[$field])->beforeLast(':')->value();
            }
        }

        return $input;
    }
}
