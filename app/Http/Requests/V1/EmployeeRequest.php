<?php
// app/Http/Requests/V1/EmployeeRequest.php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $jobTitleId = $this->input('job_title_id') ?: $this->input('jobTitleId');
        $role = $this->input('operational_role');
        $this->merge([
            'job_title_id' => $jobTitleId !== '' ? $jobTitleId : null,
            'jobTitleId' => $this->input('jobTitleId') ?: ($jobTitleId ? (string) $jobTitleId : null),
            'vehicle_type' => $role === 'delivery_driver' ? $this->input('vehicle_type') : null,
            'is_operations_enabled' => $this->boolean('is_operations_enabled'),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('employee')?->id;

        return [
            'name'          => ['required', 'string', 'max:255'],
            'phone'         => ['required', 'string', 'max:20'],
            'email'         => ['nullable', 'email'],
            'address'       => ['nullable', 'string'],
            'nationalId'    => ['nullable', 'string'],
            'dob'           => ['nullable', 'date'],

            'branch_id'     => ['required', 'exists:branches,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'job_title_id'  => ['nullable', 'exists:job_titles,id'],
            'jobTitleId'    => ['nullable', 'string'],
            'typeId'        => ['nullable', 'string'],
            'hireDate'      => ['required', 'date'],
            'salary'        => ['nullable', 'numeric', 'min:0'],

            'role'          => ['required', 'string'],
            'operational_role' => ['nullable', 'in:call_center_agent,assembler,delivery_driver,manager,cashier,other'],
            'is_operations_enabled' => ['boolean'],
            'vehicle_type'  => [
                Rule::requiredIf(fn () => $this->boolean('is_operations_enabled') && $this->input('operational_role') === 'delivery_driver'),
                'nullable',
                'in:bicycle,electric_bike,motorcycle,external',
            ],
            'status'        => ['required', 'in:ACTIVE,ON_LEAVE,TERMINATED,SUSPENDED,RESIGNED'],
            'employeeId'    => ['nullable', 'string', Rule::unique('employees', 'employeeId')->ignore($id)],
            'username'      => ['nullable', 'string', Rule::unique('employees', 'username')->ignore($id)],
            'pin'           => ['nullable', 'string', 'max:10'],
            'permissions'   => ['nullable', 'array'],
            'permissions.*' => ['string'],
            'notes'         => ['nullable', 'string'],
        ];
    }
}
