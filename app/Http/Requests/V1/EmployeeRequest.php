<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('employee')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'nationalId' => ['nullable', 'string', 'max:50'],
            'dob' => ['nullable', 'date', 'before_or_equal:today'],
            'image' => ['nullable', 'string', 'max:2048'],

            'branch_id' => ['required', 'exists:branches,id'],
            'department_id' => ['required', 'exists:departments,id'],
            'jobTitleId' => ['nullable', Rule::exists('job_titles', 'id')],
            'typeId' => ['nullable', 'string', 'max:100'],
            'managerId' => array_values(array_filter([
                'nullable',
                Rule::exists('employees', 'id'),
                $id ? Rule::notIn([$id]) : null,
            ])),
            'hireDate' => ['required', 'date'],

            'salary' => ['nullable', 'numeric', 'min:0'],
            'salary_type' => ['sometimes', 'string', 'in:monthly,daily,hourly'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0', 'required_if:salary_type,hourly'],
            'daily_rate' => ['nullable', 'numeric', 'min:0', 'required_if:salary_type,daily'],
            'standard_daily_hours' => ['nullable', 'numeric', 'min:0.5', 'max:24'],

            'role' => ['required', 'string', 'max:100'],
            'status' => ['required', 'in:ACTIVE,ON_LEAVE,TERMINATED,SUSPENDED,RESIGNED'],
            'employeeId' => ['nullable', 'string', 'max:100', Rule::unique('employees', 'employeeId')->ignore($id)],
            'username' => ['nullable', 'string', 'max:100', Rule::unique('employees', 'username')->ignore($id)],
            'password' => ['nullable', 'string', 'min:6', 'max:255'],
            'pin' => ['nullable', 'string', 'max:10'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
