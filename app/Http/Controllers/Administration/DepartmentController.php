<?php
// app/Http/Controllers/DepartmentController.php

namespace App\Http\Controllers\Administration;


use App\Http\Controllers\ApiController;
use App\Http\Requests\V1\DepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;

class DepartmentController extends ApiController
{
    public function index()
    {
        $departments = Department::all();
        return $this->success('Departments fetched', DepartmentResource::collection($departments));
    }

    public function store(DepartmentRequest $request)
    {
        $department = Department::create($request->validated());
        return $this->success('Department created', new DepartmentResource($department), 201);
    }

    public function show(Department $department)
    {
        return $this->success('Department fetched', new DepartmentResource($department));
    }

    public function update(DepartmentRequest $request, Department $department)
    {
        $department->update($request->validated());
        return $this->success('Department updated', new DepartmentResource($department));
    }

    public function destroy(Department $department)
    {
        $department->delete();
        return $this->success('Department deleted', []);
    }
}
