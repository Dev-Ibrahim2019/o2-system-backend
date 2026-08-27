<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'employeeId' => $this->employeeId,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'nationalId' => $this->nationalId,
            'dob' => $this->dob?->format('Y-m-d'),
            'image' => $this->image,

            'branch_id' => $this->branch_id,
            'branchId' => $this->branch_id,
            'department_id' => $this->department_id,
            'departmentId' => $this->department_id,
            'jobTitleId' => $this->jobTitleId,
            'typeId' => $this->typeId,
            'managerId' => $this->managerId,
            'hireDate' => $this->hireDate?->format('Y-m-d'),

            'salary' => $this->salary !== null ? (float) $this->salary : null,
            'salary_type' => $this->salary_type ?? 'monthly',
            'hourly_rate' => $this->hourly_rate !== null ? (float) $this->hourly_rate : null,
            'daily_rate' => $this->daily_rate !== null ? (float) $this->daily_rate : null,
            'standard_daily_hours' => $this->standard_daily_hours !== null ? (float) $this->standard_daily_hours : 8,

            'role' => $this->role,
            'status' => $this->status,
            'username' => $this->username,
            'permissions' => $this->permissions ?? [],
            'notes' => $this->notes,
            'rating' => (float) $this->rating,
            'performance' => $this->performance ?? [],

            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ] : null),
            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ] : null),
            'job_title' => $this->whenLoaded('jobTitle', fn () => $this->jobTitle ? [
                'id' => $this->jobTitle->id,
                'name' => $this->jobTitle->name,
                'description' => $this->jobTitle->description,
                'is_active' => (bool) $this->jobTitle->is_active,
            ] : null),
            'manager' => $this->whenLoaded('manager', fn () => $this->manager ? [
                'id' => $this->manager->id,
                'name' => $this->manager->name,
            ] : null),
        ];
    }
}
