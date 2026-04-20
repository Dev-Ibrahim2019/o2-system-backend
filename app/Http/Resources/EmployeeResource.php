<?php
// app/Http/Resources/EmployeeResource.php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'employeeId'    => $this->employeeId,
            'phone'         => $this->phone,
            'email'         => $this->email,
            'address'       => $this->address,
            'nationalId'    => $this->nationalId,
            'dob'           => $this->dob?->format('Y-m-d'),
            'image'         => $this->image,

            'branch_id'     => $this->branch_id,
            'branchId'      => $this->branch_id,
            'department_id' => $this->department_id,
            'departmentId'  => $this->department_id,
            'jobTitleId'    => $this->jobTitleId,
            'typeId'        => $this->typeId,
            'hireDate'      => $this->hireDate?->format('Y-m-d'),
            'salary'        => $this->salary,

            'role'          => $this->role,
            'status'        => $this->status,
            'username'      => $this->username,
            'permissions'   => $this->permissions ?? [],
            'notes'         => $this->notes,
            'rating'        => $this->rating,
            'performance'   => $this->performance,

            'branch'        => $this->whenLoaded('branch', fn() => [
                'id'   => $this->branch->id,
                'name' => $this->branch->name,
            ]),
            'department'    => $this->whenLoaded('department', fn() => [
                'id'   => $this->department->id,
                'name' => $this->department->name,
            ]),
        ];
    }
}