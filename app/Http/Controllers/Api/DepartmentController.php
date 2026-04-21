<?php
// app/Http/Controllers/Api/DepartmentController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\StoreDepartmentRequest;
use App\Http\Requests\Api\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use Illuminate\Support\Arr;

class DepartmentController extends ApiController
{
    public function index()
    {
        $departments = Department::all();
        return $this->success('Departments fetched', DepartmentResource::collection($departments));
    }

    public function store(StoreDepartmentRequest $request)
    {
        $data = $request->validated();

        $department = Department::create(Arr::except($data, ['branch_ids']));

        if (!empty($data['branch_ids'])) {
            $department->branches()->attach($data['branch_ids']);
        }

        return $this->success('Department created', new DepartmentResource($department->load(['parent', 'children', 'branches', 'items'])), 201);
    }

    public function show(Department $department)
    {
        return $this->success('Department fetched', new DepartmentResource($department));
    }

    public function update(UpdateDepartmentRequest $request, Department $department)
    {
        $data = $request->validated();

        $department->update(Arr::except($data, ['branch_ids', 'nameAr', 'location', 'status']));

        if (isset($data['branch_ids'])) {
            $department->branches()->sync($data['branch_ids']);
        }

        return $this->success('Department updated', new DepartmentResource($department->load(['parent', 'children', 'branches', 'items'])));
    }

    public function destroy(Department $department)
    {
        $department->delete();
        return $this->success('Department deleted', []);
    }
}
