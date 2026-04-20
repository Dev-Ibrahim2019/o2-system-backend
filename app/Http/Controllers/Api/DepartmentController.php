<?php
// app/Http/Controllers/DepartmentController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Requests\V1\DepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Branch;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

class DepartmentController extends ApiController
{
    public function index()
    {
        $departments = Department::all();

        return $this->success('Departments fetched', DepartmentResource::collection($departments));
    }

    public function store(DepartmentRequest $request)
    {
        $data = $request->validated();

        $department = Department::create(Arr::except($data, ['branch_ids']));

        if (!empty($data['branch_ids'])) {
            $department->branches()->attach($data['branch_ids']);
        }

        return new DepartmentResource($department->load('branches'));
    }

    public function show(Department $department)
    {
        $department->load([
            'branches',
            'departmentItems.item.group',
        ]);

        return $this->success('Department fetched', new DepartmentResource($department));
    }

    public function update(DepartmentRequest $request, Department $department)
    {
        $data = $request->validated();

        $department->update(Arr::except($data, ['branch_ids']));

        if (isset($data['branch_ids'])) {
            $department->branches()->sync($data['branch_ids']);
        }

        return new DepartmentResource($department->load('branches'));
    }

    public function destroy(Department $department)
    {
        $department->delete();

        return $this->success('Department deleted', []);
    }

    public function attachBranch(Department $department, Branch $branch): JsonResponse
    {
        if ($department->branches()->where('branch_id', $branch->id)->exists()) {
            return response()->json(['message' => 'Department is already attached to this branch.'], 422);
        }

        $department->branches()->attach($branch->id, ['is_active' => true]);

        return response()->json([
            'message'    => 'Department attached to branch successfully.',
            'department' => $department->name,
            'branch'     => $branch->name,
        ]);
    }

    public function detachBranch(Department $department, Branch $branch): JsonResponse
    {
        $department->branches()->detach($branch->id);

        return response()->json(['message' => 'Department detached from branch successfully.']);
    }
}
