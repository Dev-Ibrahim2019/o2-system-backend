<?php
// app/Http/Controllers/Api/EmployeeController.php
// ✅ إصلاح الأداء:
// 1. إضافة pagination بدل جلب كل الموظفين دفعة واحدة
// 2. select الحقول الضرورية فقط بدل SELECT *
// 3. eager loading صحيح بدون N+1

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
        $employees = Employee::with([
            'branch:id,name',       // ✅ select الحقول الضرورية فقط
            'department:id,name',
        ])
            ->select([                   // ✅ بدل SELECT * نختار ما نحتاجه
                'id',
                'name',
                'phone',
                'email',
                'image',
                'branch_id',
                'department_id',
                'jobTitleId',
                'role',
                'status',
                'hireDate',
                'salary',
                'employeeId',
                'username',
                'rating',
                'permissions',
                'notes',
            ])
            ->when($request->branch_id,     fn($q) => $q->where('branch_id',     $request->branch_id))
            ->when($request->department_id, fn($q) => $q->where('department_id', $request->department_id))
            ->when($request->status,        fn($q) => $q->where('status',        $request->status))
            ->when($request->search,        fn($q) => $q->where(
                fn($qb) =>
                $qb->where('name',  'like', "%{$request->search}%")
                    ->orWhere('phone', 'like', "%{$request->search}%")
                    ->orWhere('employeeId', 'like', "%{$request->search}%")
            ))
            // ✅ pagination: 50 موظف بالصفحة بدل جلب الكل
            ->paginate($request->per_page ?? 50);

        return $this->success('Employees fetched', [
            'data'       => EmployeeResource::collection($employees->items()),
            'pagination' => [
                'current_page' => $employees->currentPage(),
                'last_page'    => $employees->lastPage(),
                'total'        => $employees->total(),
                'per_page'     => $employees->perPage(),
            ],
        ]);
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
            new EmployeeResource($employee->load(['branch:id,name', 'department:id,name'])),
            201
        );
    }

    public function show(Employee $employee)
    {
        return $this->success(
            'Employee fetched',
            new EmployeeResource($employee->load(['branch:id,name', 'department:id,name']))
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
            new EmployeeResource($employee->load(['branch:id,name', 'department:id,name']))
        );
    }

    public function destroy(Employee $employee)
    {
        $employee->delete();
        return $this->success('Employee deleted', []);
    }
}