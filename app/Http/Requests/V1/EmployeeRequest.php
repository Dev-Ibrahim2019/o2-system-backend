<?php
// app/Http/Requests/V1/EmployeeRequest.php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class EmployeeRequest extends FormRequest
{
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
            'department_id' => ['required', 'exists:departments,id'],
            'jobTitleId'    => ['nullable', 'string'],
            'typeId'        => ['nullable', 'string'],
            'hireDate'      => ['required', 'date'],
            'salary'        => ['nullable', 'numeric', 'min:0'],

            'role'          => ['required', 'string'],
            'status'        => ['required', 'in:ACTIVE,ON_LEAVE,TERMINATED,SUSPENDED,RESIGNED'],
            'employeeId'    => ['nullable', 'string', "unique:employees,employeeId,{$id}"],
            'username'      => ['nullable', 'string', "unique:employees,username,{$id}"],
            'pin'           => ['nullable', 'string', 'max:10'],
            'permissions'   => ['nullable', 'array'],
            'permissions.*' => ['string'],
            'notes'         => ['nullable', 'string'],
        ];
    }
}
