<?php
// app/Http/Controllers/Administration/EmployeeController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Requests\V1\EmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use Illuminate\Http\Request;

class EmployeeController extends ApiController
{
    public function index(Request $request)
    {
        $employees = Employee::with(['branch', 'department'])
            ->when($request->branch_id,     fn($q) => $q->where('branch_id',     $request->branch_id))
            ->when($request->department_id, fn($q) => $q->where('department_id', $request->department_id))
            ->when($request->status,        fn($q) => $q->where('status',        $request->status))
            ->get();

        return $this->success('Employees fetched', EmployeeResource::collection($employees));
    }

    public function store(EmployeeRequest $request)
    {
        $data = $request->validated();

        if (!empty($data['password'])) {
            $data['password'] = bcrypt($data['password']);
        }

        $employee = Employee::create($data);

        return $this->success(
            'Employee created',
            new EmployeeResource($employee->load(['branch', 'department'])),
            201
        );
    }

    public function show(Employee $employee)
    {
        return $this->success(
            'Employee fetched',
            new EmployeeResource($employee->load(['branch', 'department']))
        );
    }

    public function update(EmployeeRequest $request, Employee $employee)
    {
        $data = $request->validated();

        if (!empty($data['password'])) {
            $data['password'] = bcrypt($data['password']);
        } else {
            unset($data['password']);
        }

        $employee->update($data);

        return $this->success(
            'Employee updated',
            new EmployeeResource($employee->load(['branch', 'department']))
        );
    }

    public function destroy(Employee $employee)
    {
        $employee->delete();
        return $this->success('Employee deleted', []);
    }
}
