<?php
// app/Http/Controllers/Api/EmployeeController.php
//
// ✅ الإصلاحات المطبّقة:
// 1. حذف إنشاء الحسابات من store() — الـ EmployeeObserver يتولى المهمة تلقائياً
// 2. حذف generateEmployeeAccountCode() و getEmployeesParentAccountId() — لم تعد ضرورية
// 3. حذف use App\Models\Account و use Illuminate\Support\Facades\DB — لم تعد ضرورية
// 4. store() أصبح بسيطاً وواضحاً بدون DB transaction يدوي

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
            'branch:id,name',
            'department:id,name',
            'jobTitle:id,name,name_ar,name_en,department_id,default_operational_role,requires_vehicle,is_active',
        ])
            ->select([
                'id',
                'name',
                'phone',
                'email',
                'image',
                'branch_id',
                'department_id',
                'jobTitleId',
                'job_title_id',
                'role',
                'operational_role',
                'vehicle_type',
                'is_operations_enabled',
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
                $qb->where('name',       'like', "%{$request->search}%")
                    ->orWhere('phone',      'like', "%{$request->search}%")
                    ->orWhere('employeeId', 'like', "%{$request->search}%")
            ))
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

        // ✅ إنشاء الموظف فقط — EmployeeObserver سيُنشئ حسابي السلف والراتب تلقائياً
        // Observer: app/Observers/EmployeeObserver.php → created()
        //   └─ AccountCreationService::createForEmployee()
        //       ├─ advance_account_id  (1130-xxx) Asset
        //       └─ salary_account_id   (2120-xxx) Liability
        $employee = Employee::create($data);

        return $this->success(
            'Employee created',
            new EmployeeResource($employee->load(['branch:id,name', 'department:id,name', 'jobTitle'])),
            201
        );
    }

    public function show(Employee $employee)
    {
        return $this->success(
            'Employee fetched',
            new EmployeeResource($employee->load(['branch:id,name', 'department:id,name', 'jobTitle']))
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
            new EmployeeResource($employee->load(['branch:id,name', 'department:id,name', 'jobTitle']))
        );
    }

    public function destroy(Employee $employee)
    {
        $employee->delete();
        return $this->success('Employee deleted', []);
    }
}
