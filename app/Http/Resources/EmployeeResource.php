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
            'job_title_id'  => $this->job_title_id,
            'typeId'        => $this->typeId,
            'hireDate'      => $this->hireDate?->format('Y-m-d'),
            'salary'        => $this->salary,

            'role'          => $this->role,
            'operational_role' => $this->operational_role,
            'vehicle_type'  => $this->vehicle_type,
            'is_operations_enabled' => (bool) $this->is_operations_enabled,
            'status'        => $this->status,
            'username'      => $this->username,
            'permissions'   => $this->permissions ?? [],
            'notes'         => $this->notes,
            'rating'        => $this->rating,
            'performance'   => $this->performance,

            'branch'        => $this->when($this->relationLoaded('branch') && $this->branch, fn() => [
                'id'   => $this->branch->id,
                'name' => $this->branch->name,
            ]),
            'department'    => $this->when($this->relationLoaded('department') && $this->department, fn() => [
                'id'   => $this->department->id,
                'name' => $this->department->name,
            ]),
            'job_title'     => $this->when($this->relationLoaded('jobTitle') && $this->jobTitle, fn() => [
                'id' => $this->jobTitle->id,
                'name' => $this->jobTitle->name,
                'name_ar' => $this->jobTitle->name_ar,
                'name_en' => $this->jobTitle->name_en,
                'default_operational_role' => $this->jobTitle->default_operational_role,
                'requires_vehicle' => (bool) $this->jobTitle->requires_vehicle,
            ]),
            'jobTitle' => $this->when($this->relationLoaded('jobTitle') && $this->jobTitle, fn() => new JobTitleResource($this->jobTitle)),
        ];
    }
}
