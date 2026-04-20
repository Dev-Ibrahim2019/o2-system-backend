<?php
// app/Http/Controllers/Api/DepartmentController.php

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
        $data = $this->prepareData($data);

        $department = Department::create(Arr::except($data, ['branch_ids', 'nameAr', 'location', 'status']));

        if (!empty($data['branch_ids'])) {
            $department->branches()->attach($data['branch_ids']);
        }

        return $this->success('Department created', new DepartmentResource($department), 201);
    }

    public function show(Department $department)
    {
        return $this->success('Department fetched', new DepartmentResource($department));
    }

    public function update(DepartmentRequest $request, Department $department)
    {
        $data = $request->validated();
        $data = $this->prepareData($data);

        $department->update(Arr::except($data, ['branch_ids', 'nameAr', 'location', 'status']));

        if (isset($data['branch_ids'])) {
            $department->branches()->sync($data['branch_ids']);
        }

        return $this->success('Department updated', new DepartmentResource($department));
    }

    public function destroy(Department $department)
    {
        $department->delete();
        return $this->success('Department deleted', []);
    }

    // ── Helper ─────────────────────────────────────────────────────────────────

    /**
     * تحويل البيانات القادمة من الفرونت لتتوافق مع الـ DB
     */
    private function prepareData(array $data): array
    {
        // تحويل status → is_active
        if (isset($data['status'])) {
            $data['is_active'] = $data['status'] === 'ACTIVE';
        }

        // تحويل type للقيم التي يقبلها الـ DB
        if (isset($data['type'])) {
            $typeMap = [
                'KITCHEN' => 'production',
                'BAR'     => 'sale',
                'GRILL'   => 'production',
                'PASTRY'  => 'production',
                'OTHER'   => 'production',
            ];
            $data['type'] = $typeMap[$data['type']] ?? $data['type'];
        }

        return $data;
    }
}
